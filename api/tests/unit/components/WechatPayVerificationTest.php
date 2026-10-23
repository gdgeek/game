<?php

namespace tests\unit\components;

use app\modules\v1\components\Wechat;
use EasyWeChat\Kernel\Exceptions\BadResponseException;
use EasyWeChat\Kernel\Exceptions\InvalidConfigException as SdkConfigException;
use EasyWeChat\Pay\Exceptions\InvalidSignatureException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Yii;
use yii\base\InvalidConfigException;

require_once dirname(__DIR__, 3) . '/modules/v1/components/Wechat.php';

class WechatPayVerificationTest extends TestCase
{
    private const PUBLIC_KEY_ID = 'PUB_KEY_ID_TEST_MIGRATION';
    private const API_KEY = '0123456789abcdef0123456789abcdef';

    private static array $merchant;
    private static array $platform;
    private static array $publicKey;
    private array $aliases;
    private array $paths = [];

    public static function setUpBeforeClass(): void
    {
        self::$merchant = self::identity(101);
        self::$platform = self::identity(202);
        self::$publicKey = self::identity(303);
    }

    protected function setUp(): void
    {
        $this->aliases = Yii::$aliases;
        Yii::setAlias('@app', dirname(__DIR__, 3));
    }

    protected function tearDown(): void
    {
        Yii::$aliases = $this->aliases;
        foreach ($this->paths as $path) {
            unlink($path);
        }
    }

    public function testPublicKeyAndOldCertificateResponsesAreBothVerified(): void
    {
        $app = $this->application();
        $app->setHttpClient(new MockHttpClient([
            $this->response('{"prepay_id":"new-key"}', self::PUBLIC_KEY_ID, self::$publicKey),
            $this->response('{"trade_state":"SUCCESS"}', self::$platform['serial'], self::$platform),
        ]));

        $this->assertSame(['prepay_id' => 'new-key'], $app->getClient()->post('v3/pay/transactions/jsapi')->toArray(false));
        $this->assertSame(['trade_state' => 'SUCCESS'], $app->getClient()->get('v3/pay/transactions/id/test')->toArray(false));
    }

    public function testEveryRequestOnTheSameClientCarriesPublicKeyId(): void
    {
        $app = $this->application(false);
        $calls = 0;
        $app->setHttpClient(new MockHttpClient(function ($method, $url, $options) use (&$calls) {
            ++$calls;
            $this->assertSame(['Wechatpay-Serial: ' . self::PUBLIC_KEY_ID], $options['normalized_headers']['wechatpay-serial'] ?? null);
            $this->assertStringContainsString('WECHATPAY2-SHA256-RSA2048', $options['normalized_headers']['authorization'][0]);
            return $this->response('{"trade_state":"NOTPAY"}', self::PUBLIC_KEY_ID, self::$publicKey);
        }));

        $client = $app->getClient();
        $client->get('v3/pay/transactions/out-trade-no/first')->toArray(false);
        $client->get('v3/pay/transactions/out-trade-no/second')->toArray(false);
        $this->assertSame(2, $calls);
    }

    public function testUnsignedSuccessfulResponseIsRejected(): void
    {
        $app = $this->application(false);
        $app->setHttpClient(new MockHttpClient(new MockResponse('{"trade_state":"SUCCESS"}', [
            'response_headers' => ['Content-Type: application/json'],
        ])));
        $this->expectException(InvalidSignatureException::class);
        $app->getClient()->get('v3/pay/transactions/id/test')->toArray(false);
    }

    public function testTamperedResponseBodyIsRejected(): void
    {
        $app = $this->application(false);
        $headers = $this->headers('{"trade_state":"NOTPAY"}', self::PUBLIC_KEY_ID, self::$publicKey);
        $app->setHttpClient(new MockHttpClient(new MockResponse('{"trade_state":"SUCCESS"}', ['response_headers' => $headers])));
        $this->expectException(InvalidSignatureException::class);
        $app->getClient()->get('v3/pay/transactions/id/test')->toArray(false);
    }

    public function testUnknownResponseSerialDoesNotFallBackToAnotherKey(): void
    {
        $app = $this->application(false);
        $app->setHttpClient(new MockHttpClient($this->response('{}', 'UNKNOWN_SERIAL', self::$publicKey)));
        $this->expectException(SdkConfigException::class);
        $app->getClient()->get('v3/pay/transactions/id/test')->toArray(false);
    }

    public function testSignedEmpty204ResponseIsSupported(): void
    {
        $app = $this->application(false);
        $app->setHttpClient(new MockHttpClient($this->response('', self::PUBLIC_KEY_ID, self::$publicKey, 204)));
        $response = $app->getClient()->post('v3/pay/transactions/out-trade-no/test/close');
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent(false));
    }

    public function testHttpErrorCannotBeReadAsSuccessfulPaymentEvenWithFalseThrowArgument(): void
    {
        $app = $this->application(false);
        $app->setHttpClient(new MockHttpClient(new MockResponse('{"trade_state":"SUCCESS"}', ['http_code' => 400])));
        $this->expectException(BadResponseException::class);
        $app->getClient()->get('v3/pay/transactions/id/test')->toArray(false);
    }

    public function testExpiredResponseSignatureIsRejected(): void
    {
        $app = $this->application(false);
        $headers = $this->headers('{}', self::PUBLIC_KEY_ID, self::$publicKey, time() - 600);
        $app->setHttpClient(new MockHttpClient(new MockResponse('{}', ['response_headers' => $headers])));
        $this->expectException(InvalidSignatureException::class);
        $app->getClient()->get('v3/pay/transactions/id/test')->toArray(false);
    }

    public function testNewPublicKeyCallbackIsVerifiedBeforePaidHandler(): void
    {
        $this->assertPaidCallback(self::PUBLIC_KEY_ID, self::$publicKey);
    }

    public function testOldCertificateCallbackIsVerifiedBeforePaidHandler(): void
    {
        $this->assertPaidCallback(self::$platform['serial'], self::$platform);
    }

    public function testCallbackTamperingIsRejectedBeforeDecryptionOrBusinessHandler(): void
    {
        $body = $this->callbackBody();
        $headers = $this->headers($body, self::PUBLIC_KEY_ID, self::$publicKey);
        $this->assertRejectedCallback($body . ' ', $headers, InvalidSignatureException::class);
    }

    public function testUnsignedCallbackIsRejectedBeforeBusinessHandler(): void
    {
        $this->assertRejectedCallback($this->callbackBody(), [], InvalidSignatureException::class);
    }

    public function testUnknownCallbackSerialIsRejectedBeforeBusinessHandler(): void
    {
        $body = $this->callbackBody();
        $this->assertRejectedCallback($body, $this->headers($body, 'UNKNOWN_SERIAL', self::$publicKey), SdkConfigException::class);
    }

    public function testWrongSigningKeyIsRejectedBeforeBusinessHandler(): void
    {
        $body = $this->callbackBody();
        $this->assertRejectedCallback($body, $this->headers($body, self::PUBLIC_KEY_ID, self::$platform), InvalidSignatureException::class);
    }

    public function testLegacySingleCertificateConfigurationRemainsUsable(): void
    {
        $wechat = $this->component();
        $wechat->pub_key_id = self::$platform['serial'];
        $wechat->pub_key_path = $this->file(self::$platform['certificate']);
        $app = $wechat->payApp();
        $app->setHttpClient(new MockHttpClient(function ($method, $url, $options) {
            $this->assertSame(['Wechatpay-Serial: ' . self::$platform['serial']], $options['normalized_headers']['wechatpay-serial'] ?? null);
            return $this->response('{}', self::$platform['serial'], self::$platform);
        }));
        $this->assertSame([], $app->getClient()->get('v3/pay/transactions/id/test')->toArray(false));
    }

    public function testMultiplePlatformCertificatePathsAreIndexedByTheirActualSerials(): void
    {
        $wechat = $this->component();
        $this->assertTrue(property_exists($wechat, 'platform_certificates'), 'Wechat must expose additional platform certificate paths.');
        $wechat->platform_certificates = json_encode([
            $this->file(self::$platform['certificate']),
            $this->file(self::$merchant['certificate']),
        ]);
        $this->assertSame([self::PUBLIC_KEY_ID, self::$platform['serial'], self::$merchant['serial']], array_map('strval', array_keys($wechat->payApp()->getMerchant()->getPlatformCerts())));
    }

    public function testMalformedCertificateJsonFailsClosed(): void
    {
        $wechat = $this->component();
        $this->assertTrue(property_exists($wechat, 'platform_certificates'));
        $wechat->platform_certificates = '{invalid json';
        $this->expectException(InvalidConfigException::class);
        $wechat->payApp();
    }

    public function testCertificateMapIsRejectedBecauseOnlyAPathListIsSupported(): void
    {
        $wechat = $this->component();
        $this->assertTrue(property_exists($wechat, 'platform_certificates'));
        $wechat->platform_certificates = '{"unexpected":"path"}';
        $this->expectException(InvalidConfigException::class);
        $wechat->payApp();
    }

    public function testUnreadableAdditionalCertificateFailsClosed(): void
    {
        $wechat = $this->component();
        $this->assertTrue(property_exists($wechat, 'platform_certificates'));
        $wechat->platform_certificates = ['/missing/wechatpay-platform.pem'];
        $this->expectException(InvalidConfigException::class);
        $wechat->payApp();
    }

    public function testPublicKeyFileCannotBeMistakenForAdditionalPlatformCertificate(): void
    {
        $wechat = $this->component();
        $this->assertTrue(property_exists($wechat, 'platform_certificates'));
        $wechat->platform_certificates = [$this->file(self::$publicKey['public'])];
        $this->expectException(InvalidConfigException::class);
        $wechat->payApp();
    }

    public function testEnvironmentConfigurationWiresAdditionalCertificatePaths(): void
    {
        $before = getenv('WECHAT_PLATFORM_CERTS');
        try {
            putenv('WECHAT_PLATFORM_CERTS=["/fixture/old-platform.pem"]');
            $config = require dirname(__DIR__, 4) . '/files/api/config/web.php';
            $this->assertSame('["/fixture/old-platform.pem"]', $config['components']['wechat']['platform_certificates'] ?? null);
        } finally {
            putenv($before === false ? 'WECHAT_PLATFORM_CERTS' : 'WECHAT_PLATFORM_CERTS=' . $before);
        }
    }

    /** @dataProvider callbackRoutes */
    #[\PHPUnit\Framework\Attributes\DataProvider('callbackRoutes')]
    public function testActualCallbackRouteServesAValidSignedNotification(string $controllerClass, string $action): void
    {
        $payApp = $this->application();
        $body = $this->callbackBody('MIGRATION.TEST');
        $payApp->setRequest(new ServerRequest('POST', 'https://example.test/notify', $this->headers($body, self::PUBLIC_KEY_ID, self::$publicKey), $body));
        $this->withController($payApp, $controllerClass, function ($controller, $app) use ($action) {
            $result = $controller->runAction($action);
            $this->assertIsString($result, 'A callback must serve the notification, not return its unexecuted SDK server.');
            $this->assertSame('SUCCESS', json_decode($result, true)['code']);
            $this->assertSame(200, $app->response->statusCode);
            $this->assertSame(\yii\web\Response::FORMAT_RAW, $app->response->format);
        });
    }

    /** @dataProvider callbackRoutes */
    #[\PHPUnit\Framework\Attributes\DataProvider('callbackRoutes')]
    public function testActualCallbackRouteRejectsUnsignedNotification(string $controllerClass, string $action): void
    {
        $payApp = $this->application();
        $payApp->setRequest(new ServerRequest('POST', 'https://example.test/notify', [], $this->callbackBody('MIGRATION.TEST')));
        $this->expectException(\yii\web\HttpException::class);
        $this->withController($payApp, $controllerClass, static function ($controller) use ($action) {
            $controller->runAction($action);
        });
    }

    public static function callbackRoutes(): array
    {
        return [
            'v1 payment' => [\app\modules\v1\controllers\WechatPayController::class, 'notify'],
            'v1 refund' => [\app\modules\v1\controllers\WechatPayController::class, 'refund-notify'],
            'v2 payment' => [\app\modules\v2\controllers\WechatPayController::class, 'notify'],
            'v2 refund' => [\app\modules\v2\controllers\WechatPayController::class, 'refund-notify'],
        ];
    }

    private function withController($payApp, string $controllerClass, callable $verify): void
    {
        $beforeApp = Yii::$app;
        $beforeContainer = Yii::$container;
        Yii::$container = new \yii\di\Container();
        $app = new \yii\web\Application([
            'id' => 'wechat-pay-route-test', 'basePath' => dirname(__DIR__, 3),
            'components' => [
                'request' => ['cookieValidationKey' => 'test-only', 'scriptUrl' => '/index.php', 'scriptFile' => __FILE__],
                'user' => ['identityClass' => \app\modules\v2\models\User::class, 'enableSession' => false],
                'wechat' => new class($payApp) extends \yii\base\Component {
                    public function __construct(private $payApp) { parent::__construct(); }
                    public function payApp() { return $this->payApp; }
                },
            ],
        ]);
        try {
            $verify(new $controllerClass('wechat-pay', $app), $app);
        } finally {
            $app->errorHandler->unregister();
            Yii::$app = $beforeApp;
            Yii::$container = $beforeContainer;
        }
    }

    private function component(): Wechat
    {
        return new Wechat([
            'app_id' => 'wx-test-app',
            'mch_id' => '1900000001',
            'private_key' => $this->file(self::$merchant['private']),
            'certificate' => $this->file(self::$merchant['certificate']),
            'secret_key' => self::API_KEY,
            'pub_key_id' => self::PUBLIC_KEY_ID,
            'pub_key_path' => $this->file(self::$publicKey['public']),
        ]);
    }

    private function application(bool $withOldCertificate = true)
    {
        $wechat = $this->component();
        if ($withOldCertificate) {
            $this->assertTrue(property_exists($wechat, 'platform_certificates'), 'Wechat must support old platform certificates alongside the public key.');
            $wechat->platform_certificates = [$this->file(self::$platform['certificate'])];
        }
        return $wechat->payApp();
    }

    private function response(string $body, string $serial, array $identity, int $status = 200): MockResponse
    {
        return new MockResponse($body, ['http_code' => $status, 'response_headers' => $this->headers($body, $serial, $identity)]);
    }

    private function headers(string $body, string $serial, array $identity, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $nonce = 'test-signature-nonce';
        openssl_sign($timestamp . "\n" . $nonce . "\n" . $body . "\n", $signature, $identity['private'], OPENSSL_ALGO_SHA256);
        return [
            'Content-Type' => 'application/json',
            'Wechatpay-Serial' => $serial,
            'Wechatpay-Timestamp' => (string) $timestamp,
            'Wechatpay-Nonce' => $nonce,
            'Wechatpay-Signature' => base64_encode($signature),
        ];
    }

    private function callbackBody(string $eventType = 'TRANSACTION.SUCCESS'): string
    {
        $nonce = '0123456789ab';
        $data = json_encode(['out_trade_no' => 'order-test', 'trade_state' => 'SUCCESS']);
        $encrypted = openssl_encrypt($data, 'aes-256-gcm', self::API_KEY, OPENSSL_RAW_DATA, $nonce, $tag, 'transaction');
        return json_encode([
            'id' => 'test-notification',
            'event_type' => $eventType,
            'resource_type' => 'encrypt-resource',
            'resource' => ['algorithm' => 'AEAD_AES_256_GCM', 'ciphertext' => base64_encode($encrypted . $tag), 'nonce' => $nonce, 'associated_data' => 'transaction'],
        ]);
    }

    private function assertPaidCallback(string $serial, array $identity): void
    {
        $app = $this->application();
        $body = $this->callbackBody();
        $app->setRequest(new ServerRequest('POST', 'https://example.test/notify', $this->headers($body, $serial, $identity), $body));
        $calls = 0;
        $server = $app->getServer();
        $server->handlePaid(function ($message) use (&$calls) {
            ++$calls;
            $this->assertSame('order-test', $message->out_trade_no);
        });
        $this->assertSame(200, $server->serve()->getStatusCode());
        $this->assertSame(1, $calls);
    }

    private function assertRejectedCallback(string $body, array $headers, string $exceptionClass): void
    {
        $app = $this->application();
        $app->setRequest(new ServerRequest('POST', 'https://example.test/notify', $headers, $body));
        $calls = 0;
        $server = $app->getServer();
        $server->handlePaid(function () use (&$calls) { ++$calls; });
        try {
            $server->serve();
            $this->fail('An invalid callback must never enter the payment handler.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exceptionClass, $error);
        }
        $this->assertSame(0, $calls);
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wechat-pay-test-');
        file_put_contents($path, $contents);
        $this->paths[] = $path;
        return $path;
    }

    private static function identity(int $serial): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'wechat-pay-offline-test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256'], $serial);
        openssl_pkey_export($key, $private);
        openssl_x509_export($cert, $certificate);
        return ['private' => $private, 'public' => openssl_pkey_get_details($key)['key'], 'certificate' => $certificate, 'serial' => strtoupper(openssl_x509_parse($cert)['serialNumberHex'])];
    }
}

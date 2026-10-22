<?php

namespace tests\unit\components;

use app\components\MiniProgramFileService;
use app\modules\v2\models\Setup;
use app\modules\v2\controllers\WechatPayController;
use app\modules\v2\controllers\FileController;
use app\modules\v2\models\User;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\web\Application;
use yii\web\HttpException;

class MiniProgramFileAccessTest extends TestCase
{
    private $originalApp;
    private $aliases;
    private $container;
    private Application $app;
    private User $user;

    protected function setUp(): void
    {
        $this->originalApp = Yii::$app;
        $this->aliases = Yii::$aliases;
        $this->container = Yii::$container;
        Yii::$container = new \yii\di\Container();
        $this->app = new Application([
            'id' => 'mini-file-test', 'basePath' => dirname(__DIR__, 3),
            'components' => [
                'db' => ['class' => 'yii\db\Connection', 'dsn' => 'sqlite::memory:'],
                'request' => ['cookieValidationKey' => 'test-only', 'scriptUrl' => '/index.php', 'scriptFile' => __FILE__],
                'user' => ['identityClass' => User::class, 'enableSession' => false],
            ],
        ]);
        $db = $this->app->db;
        $db->createCommand('CREATE TABLE user (id INTEGER PRIMARY KEY, openid TEXT, unionid TEXT, tel TEXT, role TEXT)')->execute();
        $db->createCommand('CREATE TABLE control (id INTEGER PRIMARY KEY, user_id INTEGER)')->execute();
        $db->createCommand()->batchInsert('user', ['id', 'openid', 'unionid', 'role'], [
            [7, 'owner-open', 'owner-union', 'user'], [8, 'other-open', 'other-union', 'user'],
        ])->execute();
        $db->createCommand('CREATE TABLE file (id INTEGER PRIMARY KEY, user_id INTEGER, unionid TEXT, key TEXT, unlocked INTEGER DEFAULT 0)')->execute();
        $db->createCommand('CREATE TABLE setup (id INTEGER PRIMARY KEY, device_id INTEGER, money REAL DEFAULT 0)')->execute();
        require_once dirname(__DIR__, 3) . '/migrations/m261023_150000_add_download_price_to_file.php';
        (new \m261023_150000_add_download_price_to_file(['db' => $db, 'compact' => true]))->safeUp();
        $db->createCommand()->batchInsert('file', ['id', 'user_id', 'unionid', 'key', 'unlocked', 'price'], [
            [1, 7, 'owner-union', 'paid.mp4', 0, 100],
            [2, 8, 'other-union', 'other.mp4', 0, 100],
            [3, 7, 'owner-union', 'free.mp4', 0, 0],
            [4, 7, 'owner-union', 'legacy.mp4', 0, null],
            [5, null, 'owner-union', 'old-owned.mp4', 1, null],
            [6, null, '', 'no-owner.mp4', 0, 0],
        ])->execute();
        $this->user = new User();
        $this->user->setAttributes(['id' => 7, 'openid' => 'owner-open', 'unionid' => 'owner-union'], false);
        $this->app->user->setIdentity($this->user);
    }

    protected function tearDown(): void
    {
        $this->app->db->close();
        $this->app->errorHandler->unregister();
        Yii::$app = $this->originalApp;
        Yii::$aliases = $this->aliases;
        Yii::$container = $this->container;
    }

    private function order(array $changes = []): array
    {
        $service = new MiniProgramFileService();
        $prepared = $service->reserveOrder('test-order-123', $service->findOwned(1, $this->user), $this->user);
        return array_replace_recursive([
            'out_trade_no' => 'test-order-123', 'trade_state' => 'SUCCESS',
            'appid' => 'test-app', 'mchid' => 'test-merchant',
            'payer' => ['openid' => 'owner-open'],
            'amount' => ['total' => 100, 'currency' => 'CNY'],
            'attach' => $prepared['attach'],
        ], $changes);
    }

    public function testOnlyExactOwnerFilesAreListed(): void
    {
        $service = new MiniProgramFileService();
        $this->assertSame([1, 3, 4, 5], array_map('intval', $service->ownedQuery($this->user)->orderBy('id')->select('id')->column()));
        $this->user->unionid = '';
        $this->assertSame([1, 3, 4], array_map('intval', $service->ownedQuery($this->user)->orderBy('id')->select('id')->column()));
    }

    public function testOtherUsersFileCannotBeRead(): void
    {
        $this->expectException(HttpException::class);
        (new MiniProgramFileService())->findOwned(2, $this->user);
    }

    public function testRestFileWritesAreDisabledAndListUsesTheCurrentAccount(): void
    {
        $controller = new FileController('file', $this->app);
        $actions = $controller->actions();
        foreach (['create', 'update', 'delete'] as $action) {
            $this->assertArrayNotHasKey($action, $actions);
        }
        $this->assertSame([5, 4, 3, 1], array_map(static fn ($file) => (int) $file->id, $controller->actionList('other-union')));
        $this->assertSame(1, (int) $actions['view']['findModel'](1)->id);
    }

    public function testMissingPriceIsNotTreatedAsFree(): void
    {
        $service = new MiniProgramFileService();
        $this->expectException(HttpException::class);
        $service->prepareOrder($service->findOwned(4, $this->user), $this->user, 0);
    }

    public function testOrderUsesStoredPriceAndCurrentIdentity(): void
    {
        $service = new MiniProgramFileService();
        $prepared = $service->prepareOrder($service->findOwned(1, $this->user), $this->user, 100, 'owner-open');
        $this->assertSame(100, $prepared['amount']);
        $this->assertSame('owner-open', $prepared['openid']);
        $this->assertSame(['v' => 1, 'file_id' => 1, 'user_id' => 7, 'amount' => 100], json_decode($prepared['attach'], true));
    }

    public function testClientCannotReducePrice(): void
    {
        $service = new MiniProgramFileService();
        $this->expectException(HttpException::class);
        $service->prepareOrder($service->findOwned(1, $this->user), $this->user, 1);
    }

    public function testClientCannotSubstitutePayer(): void
    {
        $service = new MiniProgramFileService();
        $this->expectException(HttpException::class);
        $service->prepareOrder($service->findOwned(1, $this->user), $this->user, 100, 'other-open');
    }

    public function testVerifiedPaymentUnlocksOnlyBoundFileAndIsIdempotent(): void
    {
        $service = new MiniProgramFileService();
        $order = $this->order();
        $this->assertTrue($service->confirmPayment($order, $this->user, 'test-app', 'test-merchant'));
        $this->assertTrue($service->confirmPayment($order, $this->user, 'test-app', 'test-merchant'));
        $this->assertSame(1, (int) $service->findOwned(1, $this->user)->unlocked);
        $this->assertSame(0, (int) $service->findOwned(3, $this->user)->unlocked);
    }

    public function testPendingOrderDoesNotUnlock(): void
    {
        $service = new MiniProgramFileService();
        $this->assertTrue(method_exists($service, 'reserveOrder'), 'Pending orders need a persisted ownership binding before WeChat creation.');
        $service->reserveOrder('test-pending-123', $service->findOwned(1, $this->user), $this->user);
        $this->assertFalse($service->confirmPayment([
            'out_trade_no' => 'test-pending-123', 'trade_state' => 'NOTPAY',
            'appid' => 'test-app', 'mchid' => 'test-merchant',
        ], $this->user, 'test-app', 'test-merchant'));
        $this->assertSame(0, (int) $service->findOwned(1, $this->user)->unlocked);
    }

    public function testMigrationStoresOrdersAndKeepsHistoricalPricesUnknown(): void
    {
        $schema = $this->app->db->schema->getTableSchema('file_payment_order', true);
        $this->assertNotNull($schema, 'Orders must persist their owner, file and price before requesting WeChat.');
        $this->assertSame(['out_trade_no'], $schema->primaryKey);
        $this->assertNull((new MiniProgramFileService())->findOwned(4, $this->user)->price);
    }

    public function testPersistedSetupFloatIsNormalizedWithoutMakingApiAmountsPermissive(): void
    {
        $this->assertTrue(method_exists(MiniProgramFileService::class, 'recordingPriceInCents'), 'Database FLOAT prices need their own integer normalization.');
        foreach ([0, 100, 2147483647] as $index => $price) {
            $this->app->db->createCommand()->insert('setup', ['id' => $index + 1, 'money' => $price])->execute();
            $stored = Setup::findOne($index + 1)->money;
            $this->assertIsFloat($stored);
            $this->assertSame($price, MiniProgramFileService::recordingPriceInCents($stored));
        }
        foreach ([1.5, -1.0, INF, NAN, 2147483648.0, null, false] as $invalid) {
            $this->assertNull(MiniProgramFileService::recordingPriceInCents($invalid));
        }
        foreach ([100.0, 1.5, '1.5', '1e2', false] as $invalid) {
            $this->assertNull(MiniProgramFileService::priceInCents($invalid));
        }
    }

    public function testSameOrderCanBeRetriedButCannotBeRebound(): void
    {
        $service = new MiniProgramFileService();
        $file = $service->findOwned(1, $this->user);
        $first = $service->reserveOrder('retry-order-123', $file, $this->user);
        $this->assertSame($first, $service->reserveOrder('retry-order-123', $file, $this->user));
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM file_payment_order')->queryScalar());
        $this->app->db->createCommand()->update('file', ['price' => 100], ['id' => 3])->execute();
        try {
            $service->reserveOrder('retry-order-123', $service->findOwned(3, $this->user), $this->user);
            $this->fail('An existing order cannot be rebound to another file.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->statusCode);
        }
        $this->app->db->createCommand()->update('file', ['price' => 200], ['id' => 1])->execute();
        $this->expectException(HttpException::class);
        $service->reserveOrder('retry-order-123', $service->findOwned(1, $this->user), $this->user);
    }

    public function testAnotherUserCannotQueryOrReuseAnOrderWithoutPaymentFields(): void
    {
        $service = new MiniProgramFileService();
        $service->reserveOrder('owner-order-123', $service->findOwned(1, $this->user), $this->user);
        $other = new User();
        $other->setAttributes(['id' => 8, 'openid' => 'other-open', 'unionid' => 'other-union'], false);
        foreach (['NOTPAY', 'CLOSED'] as $state) {
            try {
                $service->confirmPayment(['out_trade_no' => 'owner-order-123', 'trade_state' => $state, 'appid' => 'test-app', 'mchid' => 'test-merchant'], $other, 'test-app', 'test-merchant');
                $this->fail('An order without payment fields still requires its original owner.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->statusCode);
            }
        }
        $this->expectException(HttpException::class);
        $service->reserveOrder('owner-order-123', $service->findOwned(2, $other), $other);
    }

    public function testUnknownOrderCannotUseValidPaymentAttachment(): void
    {
        $service = new MiniProgramFileService();
        $this->expectException(HttpException::class);
        $service->confirmPayment($this->order(['out_trade_no' => 'unknown-order-123']), $this->user, 'test-app', 'test-merchant');
    }

    public function testClosedOrderCanOmitSuccessFieldsButMustMatchMerchant(): void
    {
        $service = new MiniProgramFileService();
        $service->reserveOrder('closed-order-123', $service->findOwned(1, $this->user), $this->user);
        $order = ['out_trade_no' => 'closed-order-123', 'trade_state' => 'CLOSED', 'appid' => 'test-app', 'mchid' => 'test-merchant'];
        $this->assertFalse($service->confirmPayment($order, $this->user, 'test-app', 'test-merchant'));
        $this->expectException(HttpException::class);
        $service->confirmPayment(array_replace($order, ['mchid' => 'other-merchant']), $this->user, 'test-app', 'test-merchant');
    }

    public function testControllerReservesOrderBeforeRequestingWechatAndRetainsBindingOnTimeout(): void
    {
        $client = new MiniProgramPayTestClient();
        $client->onPost = function (array $options): void {
            $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM file_payment_order WHERE out_trade_no = :number', [':number' => 'create-order-123'])->queryScalar());
            $this->assertSame(100, $options['json']['amount']['total']);
            $this->assertSame('owner-open', $options['json']['payer']['openid']);
            $this->assertStringContainsString('/v2/wechat-pay/notify', $options['json']['notify_url']);
            throw new \RuntimeException('Simulated transport timeout');
        };
        $this->setWechat($client);
        $this->app->request->setBodyParams(['out_trade_no' => 'create-order-123', 'file_id' => 1, 'amount' => 100, 'openid' => 'owner-open']);
        $result = (new WechatPayController('wechat-pay', $this->app))->actionWxpayOrder();
        $this->assertSame(500, $result['code']);
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM file_payment_order')->queryScalar());
    }

    public function testControllerReturnsPendingContractWithoutSuccessFields(): void
    {
        $service = new MiniProgramFileService();
        $service->reserveOrder('controller-pending', $service->findOwned(1, $this->user), $this->user);
        $client = new MiniProgramPayTestClient();
        $client->getResponse = ['out_trade_no' => 'controller-pending', 'trade_state' => 'NOTPAY', 'appid' => 'test-app', 'mchid' => 'test-merchant'];
        $this->setWechat($client);
        $this->app->request->setQueryParams(['out_trade_no' => 'controller-pending']);
        $result = (new WechatPayController('wechat-pay', $this->app))->actionWxpayQueryOrderByOutTradeNo();
        $this->assertSame(0, $result['code']);
        $this->assertSame('NOTPAY', $result['data']['trade_state']);
        $this->assertFalse($result['data']['unlocked']);
    }

    public function testControllerRejectsOtherUsersOrderBeforeRemoteQuery(): void
    {
        $service = new MiniProgramFileService();
        $service->reserveOrder('controller-foreign', $service->findOwned(1, $this->user), $this->user);
        $client = new MiniProgramPayTestClient();
        $this->setWechat($client);
        $this->app->user->setIdentity(User::findOne(8));
        $this->app->request->setQueryParams(['out_trade_no' => 'controller-foreign']);
        try {
            (new WechatPayController('wechat-pay', $this->app))->actionWxpayQueryOrderByOutTradeNo();
            $this->fail('Foreign order must not reach WeChat.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->statusCode);
            $this->assertSame([], $client->gets);
        }
    }

    public function testRealEasyWechatCallbackUsesReservedOwnerAndQueriesBeforeUnlock(): void
    {
        $client = new MiniProgramPayTestClient();
        $client->getResponse = $this->order();
        $server = $this->paymentServer([
            'out_trade_no' => 'test-order-123', 'trade_state' => 'SUCCESS',
            // Callback-provided owner is ignored; the reserved account is authoritative.
            'attach' => '{"user_id":8}',
        ]);
        $this->setWechat($client, $server);
        $this->app->user->setIdentity(null);
        $controller = new WechatPayController('wechat-pay', $this->app);
        $response = $controller->runAction('notify');
        $this->assertSame(200, $this->app->response->statusCode);
        $this->assertSame('SUCCESS', json_decode($response, true)['code']);
        $this->assertSame(['v3/pay/transactions/out-trade-no/test-order-123'], $client->gets);
        $this->assertSame(1, (int) (new MiniProgramFileService())->findOwned(1, $this->user)->unlocked);
    }

    public function testCallbackRequestsRetryWhenWechatHasNotConfirmedPayment(): void
    {
        $service = new MiniProgramFileService();
        $service->reserveOrder('retry-callback-123', $service->findOwned(1, $this->user), $this->user);
        $client = new MiniProgramPayTestClient();
        $client->getResponse = ['out_trade_no' => 'retry-callback-123', 'trade_state' => 'NOTPAY', 'appid' => 'test-app', 'mchid' => 'test-merchant'];
        $this->setWechat($client, $this->paymentServer(['out_trade_no' => 'retry-callback-123', 'trade_state' => 'SUCCESS']));
        (new WechatPayController('wechat-pay', $this->app))->actionNotify();
        $this->assertSame(500, $this->app->response->statusCode, 'A callback must not be acknowledged until WeChat confirms success.');
        $this->assertSame(0, (int) $service->findOwned(1, $this->user)->unlocked);
    }

    private function setWechat(MiniProgramPayTestClient $client, $server = null): void
    {
        $payApp = new class($client, $server) {
            public function __construct(private $client, private $server) {}
            public function getConfig(): array { return ['app_id' => 'test-app', 'mch_id' => 'test-merchant']; }
            public function getClient() { return $this->client; }
            public function getServer() { return $this->server; }
        };
        $this->app->set('wechat', new class($payApp) extends \yii\base\Component {
            public function __construct(private $payApp) { parent::__construct(); }
            public function payApp() { return $this->payApp; }
        });
        $this->app->urlManager->hostInfo = 'https://api.example.test';
        $this->app->urlManager->enablePrettyUrl = true;
        $this->app->urlManager->showScriptName = false;
    }

    private function paymentServer(array $payload): \EasyWeChat\Pay\Server
    {
        $secret = str_repeat('k', 32);
        $nonce = 'testnonce123';
        $associated = 'transaction';
        $ciphertext = openssl_encrypt(json_encode($payload, JSON_THROW_ON_ERROR), 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, $nonce, $tag, $associated);
        $body = json_encode(['event_type' => 'TRANSACTION.SUCCESS', 'resource' => [
            'ciphertext' => base64_encode($ciphertext . $tag), 'nonce' => $nonce, 'associated_data' => $associated,
        ]], JSON_THROW_ON_ERROR);
        $request = new \Nyholm\Psr7\ServerRequest('POST', 'https://api.example.test/v2/wechat-pay/notify', ['Content-Type' => 'application/json'], $body);
        $merchant = $this->createStub(\EasyWeChat\Pay\Contracts\Merchant::class);
        $merchant->method('getSecretKey')->willReturn($secret);
        return new \EasyWeChat\Pay\Server($merchant, $request);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOrders')]
    public function testPaymentMustMatchFileOwnerAmountAndMerchant(array $changes): void
    {
        $service = new MiniProgramFileService();
        try {
            $service->confirmPayment($this->order($changes), $this->user, 'test-app', 'test-merchant');
            $this->fail('Mismatched payment must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(0, (int) $service->findOwned(1, $this->user)->unlocked);
        }
    }

    public static function invalidOrders(): array
    {
        return [
            'amount' => [['amount' => ['total' => 1]]],
            'currency' => [['amount' => ['currency' => 'USD']]],
            'payer' => [['payer' => ['openid' => 'other-open']]],
            'app' => [['appid' => 'other-app']],
            'merchant' => [['mchid' => 'other-merchant']],
            'unbound' => [['attach' => '']],
            'foreign file' => [['attach' => '{"v":1,"file_id":2,"user_id":7,"amount":100}']],
            'foreign owner' => [['attach' => '{"v":1,"file_id":1,"user_id":8,"amount":100}']],
            'other owned file' => [['attach' => '{"v":1,"file_id":3,"user_id":7,"amount":100}']],
            'missing payer' => [['payer' => ['openid' => null]]],
        ];
    }
}

/** Only the network boundary is replaced; controller and EasyWeChat dispatch stay real. */
class MiniProgramPayTestClient
{
    public array $getResponse = [];
    public array $gets = [];
    public $onPost;

    public function get(string $path, array $options)
    {
        $this->gets[] = $path;
        return new class($this->getResponse) {
            public function __construct(private array $response) {}
            public function toArray(bool $throw): array { return $this->response; }
        };
    }

    public function post(string $path, array $options)
    {
        return ($this->onPost)($options);
    }
}

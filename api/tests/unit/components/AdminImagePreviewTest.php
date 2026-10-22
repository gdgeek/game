<?php

namespace tests\unit\components;

use app\components\AdminAuthService;
use app\components\AdminDeviceService;
use app\components\AdminImagePreviewService;
use app\components\AdminTokenService;
use app\controllers\AdminController;
use app\modules\v1\components\Secret;
use bizley\jwt\Jwt;
use PHPUnit\Framework\TestCase;
use tests\fixtures\InMemoryAdminTokenStore;
use Yii;
use yii\caching\ArrayCache;
use yii\web\Application;
use yii\web\HttpException;

require_once dirname(__DIR__, 2) . '/fixtures/InMemoryAdminTokenStore.php';

/** Uses only SQLite, fake credentials and the real local COS signer; never calls STS/COS. */
class AdminImagePreviewTest extends TestCase
{
    private const HOST = 'preview-fixture-1250000000.cos.ap-nanjing.myqcloud.com';
    private const SECRET_KEY = 'preview-test-only-secret-key';
    private $app;
    private $auth;
    private $devices;
    private $runtime;
    private $originalApp;
    private $originalAliases;
    private $originalContainer;
    private $originalServer;
    private $originalTimezone;

    protected function setUp(): void
    {
        $this->originalApp = Yii::$app;
        $this->originalAliases = Yii::$aliases;
        $this->originalContainer = Yii::$container;
        $this->originalServer = $_SERVER;
        $this->originalTimezone = date_default_timezone_get();
        Yii::$container = new \yii\di\Container();
        $this->runtime = sys_get_temp_dir() . '/game-image-preview-test-' . bin2hex(random_bytes(6));
        mkdir($this->runtime, 0700, true);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $this->app = new Application([
            'id' => 'admin-image-preview-test',
            'basePath' => dirname(__DIR__, 3),
            'runtimePath' => $this->runtime,
            'vendorPath' => dirname(__DIR__, 3) . '/vendor',
            'components' => [
                'request' => ['cookieValidationKey' => 'preview-test-only', 'scriptFile' => dirname(__DIR__, 3) . '/web/index.php', 'scriptUrl' => '/index.php'],
                'db' => ['class' => 'yii\db\Connection', 'dsn' => 'sqlite::memory:'],
                'user' => ['identityClass' => \app\modules\v2\models\User::class, 'enableSession' => false, 'enableAutoLogin' => false],
                'cache' => ['class' => ArrayCache::class],
                'jwt' => ['class' => Jwt::class, 'signer' => Jwt::HS256, 'signingKey' => ['key' => str_repeat('preview-test-jwt-key-', 3), 'method' => Jwt::METHOD_PLAIN]],
                'secret' => ['class' => Secret::class, 'id' => 'preview-test-only-id', 'key' => self::SECRET_KEY, 'cloud' => ['bucket' => 'preview-fixture-1250000000', 'region' => 'ap-nanjing']],
            ],
        ]);
        $db = $this->app->db;
        $db->open();
        $db->schema->typeMap['json'] = 'json';
        $db->schema->columnSchemaClass = 'yii\\db\\mysql\\ColumnSchema';
        $db->queryBuilder->setExpressionBuilders(['yii\\db\\JsonExpression' => 'yii\\db\\mysql\\JsonExpressionBuilder']);
        $db->createCommand('CREATE TABLE device (id INTEGER PRIMARY KEY)')->execute();
        $db->createCommand('CREATE TABLE setup (id INTEGER PRIMARY KEY, device_id INTEGER, pictures JSON, thumbs JSON)')->execute();
        $db->createCommand('CREATE TABLE user (id INTEGER PRIMARY KEY)')->execute();
        $db->createCommand('CREATE TABLE control (id INTEGER PRIMARY KEY, user_id INTEGER, device_id INTEGER)')->execute();
        $db->createCommand()->batchInsert('device', ['id'], [[1], [2], [3]])->execute();
        $db->createCommand()->insert('user', ['id' => 42])->execute();
        $db->createCommand()->insert('control', ['id' => 1, 'user_id' => 42, 'device_id' => 1])->execute();
        $this->saveSources(['https://' . self::HOST . '/picture/t1.png'], ['https://' . self::HOST . '/picture/t1.webp']);
        $db->createCommand()->insert('setup', ['id' => 2, 'device_id' => 2, 'pictures' => ['https://' . self::HOST . '/other-owner.png'], 'thumbs' => []])->execute();
        $this->auth = new AdminAuthService([
            'tokens' => new AdminTokenService(['jwt' => 'jwt', 'store' => new InMemoryAdminTokenStore()]),
            'username' => 'preview-admin', 'passwordHash' => password_hash('preview-test-only', PASSWORD_BCRYPT, ['cost' => 4]),
            'cache' => new ArrayCache(),
        ]);
        $this->devices = new AdminDeviceService(['auth' => $this->auth]);
    }

    protected function tearDown(): void
    {
        $this->app->db->close();
        $this->app->errorHandler->unregister();
        Yii::$app = $this->originalApp;
        Yii::$aliases = $this->originalAliases;
        Yii::$container = $this->originalContainer;
        $_SERVER = $this->originalServer;
        date_default_timezone_set($this->originalTimezone);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->runtime, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->runtime);
    }

    public function testAnonymousDevicePreviewIsRejectedBeforeSigning(): void
    {
        $this->assertStatus(401, fn () => $this->controller()->runAction('image-previews', ['id' => 1]));
        $this->assertStatus(401, fn () => $this->service()->getPreviews(1));
    }

    public function testRealGetSignatureExpiresAtExactlyFiveMinutesAndNeverWritesConfiguration(): void
    {
        $this->authenticate();
        $before = $this->app->db->createCommand('SELECT * FROM setup ORDER BY id')->queryAll();
        $this->preventSetupWrites();
        $start = time();
        $result = $this->controller()->runAction('image-previews', ['id' => 1]);
        $this->assertSame(['previews'], array_keys($result));
        $this->assertCount(2, $result['previews']);
        foreach ($result['previews'] as $entry) {
            $this->assertSame(['source', 'url', 'expiresAt'], array_keys($entry));
            $this->assertGreaterThanOrEqual($start + 300, $entry['expiresAt']);
            $this->assertLessThanOrEqual(time() + 300, $entry['expiresAt']);
            $this->assertValidGetSignature($entry);
            $this->assertStringNotContainsString(self::SECRET_KEY, $entry['url']);
        }
        $this->assertSame('no-store', $this->app->response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->app->db->createCommand('SELECT * FROM setup ORDER BY id')->queryAll());
        $this->assertSame(['https://' . self::HOST . '/picture/t1.png', 'https://' . self::HOST . '/picture/t1.webp'], array_column($result['previews'], 'source'));
    }

    public function testManagerCanOnlyPreviewCurrentlyControlledDevices(): void
    {
        $this->authenticate(true);
        $this->assertCount(2, $this->service()->getPreviews(1));
        foreach ([2, 3, 999] as $id) {
            $this->assertStatus(404, fn () => $this->service()->getPreviews($id));
        }
        $this->app->db->createCommand()->delete('control', ['user_id' => 42])->execute();
        $this->assertStatus(404, fn () => $this->controller()->runAction('image-previews', ['id' => 1]));
    }

    public function testMissingDeviceIsNotFoundAndMissingSetupRemainsMissing(): void
    {
        $this->authenticate();
        $this->preventSetupWrites();
        $this->assertSame([], $this->service()->getPreviews(3));
        $this->assertSame(0, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM setup WHERE device_id = 3')->queryScalar());
        $this->assertStatus(404, fn () => $this->service()->getPreviews(999));
    }

    public function testInvalidAndExternalUrlsNeverReceiveSignatures(): void
    {
        $this->authenticate();
        $base = 'https://' . self::HOST;
        $sources = [
            'https://public.example.com/image.png', 'https://other-1250000000.cos.ap-nanjing.myqcloud.com/a.png',
            $base . '.evil.example/a.png', 'https://evil.example/' . self::HOST . '/a.png',
            'https://user@' . self::HOST . '/a.png', 'https://' . self::HOST . ':443/a.png',
            'https://' . self::HOST . ':8443/a.png', 'http://' . self::HOST . '/a.png',
            '//' . self::HOST . '/a.png', $base . '/', $base . '/a/../secret.png', $base . '/a/%2e%2e/secret.png',
            $base . '/bad%ZZ.png', $base . '/bad%00.png', $base . '/bad%5cname.png', "https://" . self::HOST . "/bad\nname.png",
            'data:image/png;base64,AAAA', 'not a url',
        ];
        $this->saveSources($sources);
        $this->app->secret->key = '';
        $this->assertSame([], $this->service()->getPreviews(1));
    }

    public function testSavedQueriesAndFragmentsAreNotForwardedToCosSigner(): void
    {
        $this->authenticate();
        $source = 'https://' . self::HOST . '/picture/t1.png?sign=expired&response-content-type=text/html&ci-process=bad#fragment';
        $this->saveSources([$source, $source]);
        $entries = $this->service()->getPreviews(1);
        $this->assertCount(1, $entries);
        $this->assertSame($source, $entries[0]['source']);
        $this->assertValidGetSignature($entries[0]);
        $parts = parse_url($entries[0]['url']);
        $this->assertArrayNotHasKey('fragment', $parts);
        parse_str($parts['query'], $query);
        $this->assertSame(['sign'], array_keys($query));
        $this->assertStringNotContainsString('expired', $entries[0]['url']);
    }

    public function testEncodedObjectKeysAreDecodedExactlyOnce(): void
    {
        $this->authenticate();
        $sources = ['https://' . self::HOST . '/picture/a%20b%252Fc.png', 'https://' . self::HOST . '/picture/%E4%B8%AD%E6%96%87.png'];
        $this->saveSources($sources);
        $entries = $this->service()->getPreviews(1);
        $this->assertSame($sources, array_column($entries, 'source'));
        foreach ($entries as $index => $entry) {
            $this->assertSame(rawurldecode(parse_url($sources[$index], PHP_URL_PATH)), rawurldecode(parse_url($entry['url'], PHP_URL_PATH)));
            $this->assertValidGetSignature($entry);
        }
    }

    public function testEmptyOrExternalImagesDoNotRequireCosConfiguration(): void
    {
        $this->authenticate();
        $this->app->set('secret', ['class' => Secret::class, 'cloud' => []]);
        foreach ([[], ['https://public.example.com/image.png']] as $sources) {
            $this->saveSources($sources);
            $this->assertSame([], $this->service()->getPreviews(1));
        }
        $this->assertSame([], $this->service()->getPreviews(3));
    }

    public function testRequiredMissingCredentialsFailWithSanitizedUnavailableResponse(): void
    {
        $this->authenticate();
        foreach (['id', 'key'] as $field) {
            $secret = $this->app->secret;
            $before = $secret->$field;
            $secret->$field = '';
            $this->assertUnavailable(fn () => $this->service()->getPreviews(1));
            $secret->$field = $before;
        }
    }

    public function testInvalidCloudConfigurationFailsClosedOnlyForCosImages(): void
    {
        $this->authenticate();
        foreach ([[], ['bucket' => 'bucket.evil', 'region' => 'ap-nanjing'], ['bucket' => 'preview-fixture-1250000000', 'region' => 'ap-nanjing/evil']] as $cloud) {
            $this->app->secret->cloud = $cloud;
            $this->assertUnavailable(fn () => $this->service()->getPreviews(1));
        }
    }

    public function testSdkFailureDoesNotLeakCredentialsOrSignedUrls(): void
    {
        $this->authenticate();
        $this->app->secret->key = [];
        $this->assertUnavailable(fn () => $this->service()->getPreviews(1));
    }

    public function testOnlySavedSourcesAreUsedEvenWhenRequestProvidesArbitraryKeyOrOverrides(): void
    {
        $this->authenticate();
        $this->app->request->setQueryParams(['key' => 'other-owner.png', 'method' => 'PUT', 'expires' => 86400, 'response-content-type' => 'text/html', 'disposition' => 'attachment']);
        $entries = $this->controller()->runAction('image-previews', ['id' => 1])['previews'];
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('other-owner', $entry['url']);
            $this->assertValidGetSignature($entry);
            parse_str(parse_url($entry['url'], PHP_URL_QUERY), $query);
            $this->assertSame(['sign'], array_keys($query));
        }
    }

    public function testProductionRouteAndControllerAllowOnlyGet(): void
    {
        $this->assertFeature();
        $configuration = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        $configuration['components']['urlManager']['cache'] = false;
        $manager = Yii::createObject($configuration['components']['urlManager']);
        $this->app->request->setPathInfo('admin-api/devices/42/image-previews');
        $this->assertSame(['admin/image-previews', ['id' => '42']], $manager->parseRequest($this->app->request));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertFalse($manager->parseRequest($this->app->request));
        $this->authenticate();
        $this->assertStatus(405, fn () => $this->controller()->runAction('image-previews', ['id' => 1]));
    }

    private function assertFeature(): void
    {
        $this->assertTrue(class_exists(AdminImagePreviewService::class), 'The admin image preview service is not implemented yet.');
    }

    private function service(): AdminImagePreviewService
    {
        $this->assertFeature();
        return new AdminImagePreviewService(['devices' => $this->devices]);
    }

    private function controller(): AdminController
    {
        return new AdminController('admin', $this->app, ['auth' => $this->auth, 'devices' => $this->devices, 'imagePreviews' => $this->service()]);
    }

    private function authenticate(bool $manager = false): void
    {
        $identity = $manager ? ['source' => 'wechat', 'wechatUserId' => 42, 'username' => 'wechat:42']
            : ['source' => 'password', 'username' => 'preview-admin', 'credentialFingerprint' => hash('sha256', $this->auth->passwordHash)];
        $token = $this->auth->tokens->issue($identity);
        $this->app->request->headers->set('Authorization', 'Bearer ' . $token['accessToken']);
    }

    private function saveSources(array $pictures, array $thumbs = []): void
    {
        $this->app->db->createCommand()->delete('setup', ['device_id' => 1])->execute();
        $this->app->db->createCommand()->insert('setup', ['id' => 1, 'device_id' => 1, 'pictures' => $pictures, 'thumbs' => $thumbs])->execute();
    }

    private function preventSetupWrites(): void
    {
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $verb) {
            $this->app->db->pdo->exec("CREATE TRIGGER prevent_preview_{$verb} BEFORE {$verb} ON setup BEGIN SELECT RAISE(ABORT, 'Preview must never write setup'); END");
        }
    }

    private function assertStatus(int $status, callable $callback): void
    {
        try { $callback(); $this->fail('Expected HTTP ' . $status); }
        catch (HttpException $exception) { $this->assertSame($status, $exception->statusCode); }
    }

    private function assertUnavailable(callable $callback): void
    {
        try { $callback(); $this->fail('Expected unavailable COS signing to fail closed.'); }
        catch (HttpException $exception) {
            $this->assertSame(503, $exception->statusCode);
            $this->assertSame('图片预览暂时不可用，请稍后重试', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET_KEY, $exception->getMessage());
            $this->assertStringNotContainsString('sign=', $exception->getMessage());
        }
    }

    private function assertValidGetSignature(array $entry): void
    {
        $parts = parse_url($entry['url']);
        $this->assertSame('https', $parts['scheme']);
        $this->assertSame(self::HOST, $parts['host']);
        parse_str($parts['query'], $query);
        $this->assertArrayHasKey('sign', $query);
        parse_str($query['sign'], $sign);
        $this->assertSame('sha1', $sign['q-sign-algorithm']);
        $this->assertSame('host', $sign['q-header-list']);
        $this->assertSame('', $sign['q-url-param-list']);
        $this->assertSame($sign['q-sign-time'], $sign['q-key-time']);
        [$start, $end] = array_map('intval', explode(';', $sign['q-sign-time']));
        $this->assertSame($entry['expiresAt'], $end);
        $this->assertGreaterThanOrEqual(time() - 62, $start);
        $this->assertLessThanOrEqual(time(), $start);
        // Independently rebuild the COS HMAC for GET. A PUT/HEAD signature cannot pass this check.
        $httpString = 'get' . "\n" . rawurldecode($parts['path']) . "\n\n" . 'host=' . rawurlencode($parts['host']) . "\n";
        $toSign = 'sha1' . "\n" . $sign['q-sign-time'] . "\n" . sha1($httpString) . "\n";
        $signKey = hash_hmac('sha1', $sign['q-sign-time'], self::SECRET_KEY);
        $this->assertSame(hash_hmac('sha1', $toSign, $signKey), $sign['q-signature']);
    }
}

<?php

namespace tests\unit\components;

use app\components\AdminAuthService;
use app\components\AdminTokenService;
use tests\fixtures\InMemoryAdminTokenStore;
use bizley\jwt\Jwt;
use app\components\AdminDeviceService;
use app\components\AdminGameCatalog;
use app\controllers\AdminController;
use app\modules\v2\helper\Server;
use app\modules\v2\models\Setup;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\caching\ArrayCache;
use yii\web\Application;
use yii\web\BadRequestHttpException;
use yii\web\HttpException;

require_once dirname(__DIR__, 2) . '/fixtures/InMemoryAdminTokenStore.php';

/** Run independently of the deployment-only api/config directory. */
class AdminApiTest extends TestCase
{
    private $app;
    private $auth;
    private $catalog;
    private $devices;
    private $runtime;
    private $originalApp;
    private $originalAliases;
    private $originalContainer;
    private $originalServer;
    private $originalSessionSettings;
    private $originalSessionId;
    private $originalSessionData;
    private $originalSessionWasSet;
    private $originalSessionWasActive;

    protected function setUp(): void
    {
        $this->originalApp = Yii::$app;
        $this->originalAliases = Yii::$aliases;
        $this->originalContainer = Yii::$container;
        Yii::$container = new \yii\di\Container();
        $this->originalServer = $_SERVER;
        $this->originalSessionSettings = ini_get_all('session', false);
        $this->originalSessionId = session_id();
        $this->originalSessionWasSet = array_key_exists('_SESSION', $GLOBALS);
        $this->originalSessionData = $_SESSION ?? [];
        $this->originalSessionWasActive = session_status() === PHP_SESSION_ACTIVE;
        if ($this->originalSessionWasActive) {
            session_write_close();
        }
        // The isolated fixture must never reuse or destroy the runner's session.
        session_id('');
        $this->runtime = sys_get_temp_dir() . '/game-admin-test-' . bin2hex(random_bytes(6));
        mkdir($this->runtime, 0700, true);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $this->app = new Application([
            'id' => 'admin-api-test',
            'basePath' => dirname(__DIR__, 3),
            'runtimePath' => $this->runtime,
            'vendorPath' => dirname(__DIR__, 3) . '/vendor',
            'components' => [
                'request' => [
                    'cookieValidationKey' => 'isolated-test-cookie-key',
                    'enableCsrfCookie' => false,
                    'parsers' => ['application/json' => 'yii\web\JsonParser'],
                    'scriptFile' => dirname(__DIR__, 3) . '/web/index.php',
                    'scriptUrl' => '/index.php',
                ],
                'session' => [
                    'class' => 'yii\web\Session',
                    'savePath' => $this->runtime,
                    'useCookies' => false,
                ],
                'db' => ['class' => 'yii\db\Connection', 'dsn' => 'sqlite::memory:'],
                'user' => ['identityClass' => \app\modules\v2\models\User::class, 'enableSession' => false, 'enableAutoLogin' => false],
                'cache' => ['class' => ArrayCache::class],
                'jwt' => ['class' => Jwt::class, 'signer' => Jwt::HS256, 'signingKey' => ['key' => str_repeat('admin-api-test-key-', 3), 'method' => Jwt::METHOD_PLAIN]],
            ],
        ]);
        $db = $this->app->db;
        $db->open();
        // SQLite stores JSON as text; use Yii's real MySQL JSON codecs so array
        // round trips match the deployed setup table without mocking the models.
        $db->schema->typeMap['json'] = 'json';
        $db->schema->columnSchemaClass = 'yii\\db\\mysql\\ColumnSchema';
        $db->queryBuilder->setExpressionBuilders(['yii\\db\\JsonExpression' => 'yii\\db\\mysql\\JsonExpressionBuilder']);
        $db->pdo->sqliteCreateFunction('NOW', static function () { return '2026-10-11 15:00:00'; });
        $db->createCommand('CREATE TABLE device (id INTEGER PRIMARY KEY, uuid TEXT, tag TEXT, ip TEXT, created_at TEXT, updated_at TEXT, version INTEGER NOT NULL DEFAULT 0)')->execute();
        $db->createCommand('CREATE TABLE setup (id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER, scene_id INTEGER, title TEXT, money REAL DEFAULT 0, slogans JSON, pictures JSON, thumbs JSON, shots JSON, updated_at TEXT)')->execute();
        $db->createCommand()->batchInsert('device', ['id', 'uuid', 'tag', 'ip', 'created_at', 'updated_at'], [
            [1, 'device-a', '大厅一号', '192.168.1.10', '2026-10-10 12:00:00', '2026-10-11 12:00:00'],
            [2, 'device-b', null, null, '2026-10-10 12:00:00', '2026-10-11 13:00:00'],
        ])->execute();
        $db->createCommand()->insert('setup', [
            'device_id' => 1, 'scene_id' => 638, 'title' => '保留标题', 'money' => 9.9,
            'slogans' => ['保留口号'], 'pictures' => ['keep.png'], 'thumbs' => ['keep.webp'],
            'shots' => [1, 5], 'updated_at' => '2026-10-11 12:30:00',
        ])->execute();
        // Tests deliberately use separate credentials, never the deployment password.
        $this->auth = new AdminAuthService([
            'tokens' => new AdminTokenService(['jwt' => 'jwt', 'store' => new InMemoryAdminTokenStore()]),
            'username' => 'test-admin',
            'passwordHash' => password_hash('test-only-password', PASSWORD_DEFAULT),
            'cache' => new ArrayCache(),
        ]);
        $this->catalog = new AdminGameCatalog([
            'cache' => new ArrayCache(),
            'loader' => static function () {
                return ['data' => [
                    ['verse_id' => '638', 'name' => '星际漫游'],
                    ['verse_id' => 700, 'name' => '音乐派对', 'description' => '节奏游戏', 'category' => '音乐'],
                ]];
            },
        ]);
        $this->devices = new AdminDeviceService(['catalog' => $this->catalog, 'auth' => $this->auth]);
        Yii::$container->setSingleton(AdminGameCatalog::class, $this->catalog);
    }

    protected function tearDown(): void
    {
        $this->app->session->destroy();
        $this->app->session->close();
        $this->app->db->close();
        $this->app->errorHandler->unregister();
        Yii::$app = $this->originalApp;
        Yii::$aliases = $this->originalAliases;
        Yii::$container = $this->originalContainer;
        $_SERVER = $this->originalServer;
        // Yii Session setters alter process-wide INI values. Restore them before
        // removing the fixture path so later Codeception tests can open sessions.
        foreach ($this->originalSessionSettings as $name => $value) {
            if (ini_get($name) !== $value) {
                ini_set($name, $value);
            }
        }
        session_id($this->originalSessionId);
        if ($this->originalSessionWasActive) {
            session_start();
        }
        if ($this->originalSessionWasSet) {
            $_SESSION = $this->originalSessionData;
        } else {
            unset($_SESSION);
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->runtime, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        @rmdir($this->runtime);
    }

    public function testLoginReturnsAdminJwtAndLogoutImmediatelyRevokesItWithoutPhpSession(): void
    {
        $before = session_status();
        $result = $this->authenticate();
        $this->assertSame(['username' => 'test-admin', 'role' => 'admin'], $result['user']);
        $this->assertSame('jwt', $result['authMode']);
        $this->assertNotEmpty($result['token']['refreshToken']);
        $this->assertSame($before, session_status());
        $this->assertSame($result['user'], $this->auth->getUser());
        $this->auth->logout($result['token']['refreshToken']);
        $this->expectException(HttpException::class);
        $this->auth->getUser();
    }

    public function testIncorrectPasswordAndUsernameShareGenericFailure(): void
    {
        foreach ([['test-admin', 'incorrect'], ['missing-user', 'test-only-password']] as $credentials) {
            try {
                $this->auth->login($credentials[0], $credentials[1]);
                $this->fail('Invalid credentials must be rejected.');
            } catch (HttpException $e) {
                $this->assertSame(401, $e->statusCode);
                $this->assertSame('用户名或密码错误', $e->getMessage());
            }
        }
        $this->assertNull($this->auth->getUser());
    }

    public function testRepeatedFailuresAreThrottledEvenWhenUsernameChanges(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $this->auth->login('invalid-' . $attempt, 'incorrect');
            } catch (HttpException $e) {
                $this->assertSame(401, $e->statusCode);
            }
        }
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        try {
            $this->authenticate();
        } catch (HttpException $e) {
            $this->assertSame(429, $e->statusCode);
            $retryAfter = (int) $this->app->response->headers->get('Retry-After');
            $this->assertGreaterThanOrEqual(1, $retryAfter);
            $this->assertLessThanOrEqual(60, $retryAfter);
            throw $e;
        }
    }

    public function testLegacyFifteenMinuteLockDoesNotBlockTheOneMinutePolicy(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->auth->tokens->store->reservePasswordAttempt($this->app->request->userIP, 5, 900, $this->auth->tokens->getIssuer());
        }

        $this->assertSame(['username' => 'test-admin', 'role' => 'admin'], $this->authenticate()['user']);
    }

    public function testPasswordFailureLimitIsSharedAcrossAuthServiceInstancesAndSuccessClearsIt(): void
    {
        $store = $this->auth->tokens->store;
        $second = new AdminAuthService([
            'tokens' => new AdminTokenService(['jwt' => 'jwt', 'store' => $store]),
            'username' => 'test-admin', 'passwordHash' => $this->auth->passwordHash,
            'cache' => new ArrayCache(),
        ]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try { ($attempt % 2 ? $this->auth : $second)->login('wrong-' . $attempt, 'incorrect'); }
            catch (HttpException $e) { $this->assertSame(401, $e->statusCode); }
        }
        try { $second->login('test-admin', 'incorrect'); $this->fail('A second node must see the shared limit.'); }
        catch (HttpException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertGreaterThan(0, (int) Yii::$app->response->headers->get('Retry-After'));
        }
        try { $this->auth->login('test-admin', 'test-only-password'); $this->fail('The saturated window must remain blocked until expiry.'); }
        catch (HttpException $e) { $this->assertSame(429, $e->statusCode); }
        $clearStore = new InMemoryAdminTokenStore();
        $clearAuth = new AdminAuthService(['tokens' => new AdminTokenService(['jwt' => 'jwt', 'store' => $clearStore]), 'username' => 'test-admin', 'passwordHash' => $this->auth->passwordHash, 'cache' => new ArrayCache()]);
        try { $clearAuth->login('test-admin', 'incorrect'); } catch (HttpException $e) { $this->assertSame(401, $e->statusCode); }
        $this->assertSame(['username' => 'test-admin', 'role' => 'admin'], $clearAuth->login('test-admin', 'test-only-password')['user']);
        try { $clearAuth->login('test-admin', 'incorrect'); }
        catch (HttpException $e) { $this->assertSame(401, $e->statusCode); return; }
        $this->fail('Successful authentication must clear the shared failure window.');
    }

    public function testChangingPasswordInvalidatesExistingJwtAndRefresh(): void
    {
        $result = $this->authenticate();
        $this->auth->passwordHash = password_hash('replacement-password', PASSWORD_DEFAULT);
        try { $this->auth->getUser(); $this->fail('Changed credentials must reject the old bearer.'); } catch (HttpException $e) { $this->assertSame(401, $e->statusCode); }
        $this->expectException(HttpException::class);
        $this->auth->refresh($result['token']['refreshToken']);
    }

    public function testAdminJwtHasAnEightHourAbsoluteDeadline(): void
    {
        $now = time(); $result = $this->authenticate();
        $this->assertGreaterThanOrEqual($now + 28800, strtotime($result['token']['refreshExpires']));
        $this->assertLessThanOrEqual(time() + 28800, strtotime($result['token']['refreshExpires']));
    }

    public function testAuthenticationDoesNotNeedTheApplicationRedisCache(): void
    {
        $this->app->set('cache', ['class' => 'yii\\redis\\Cache', 'redis' => ['class' => 'yii\\redis\\Connection', 'hostname' => 'unavailable.invalid']]);
        $auth = new AdminAuthService(['tokens' => $this->auth->tokens, 'username' => 'test-admin', 'passwordHash' => $this->auth->passwordHash]);
        $this->assertSame(['username' => 'test-admin', 'role' => 'admin'], $auth->login('test-admin', 'test-only-password')['user']);
    }

    public function testCredentialsCanBeOverriddenByEnvironment(): void
    {
        $originalUsername = getenv('ADMIN_USERNAME');
        $originalHash = getenv('ADMIN_PASSWORD_HASH');
        putenv('ADMIN_USERNAME=environment-admin');
        putenv('ADMIN_PASSWORD_HASH=' . password_hash('environment-test-password', PASSWORD_DEFAULT));
        try {
            $auth = new AdminAuthService(['tokens' => $this->auth->tokens, 'cache' => new ArrayCache()]);
            $this->assertSame(['username' => 'environment-admin', 'role' => 'admin'], $auth->login('environment-admin', 'environment-test-password')['user']);
        } finally {
            putenv($originalUsername === false ? 'ADMIN_USERNAME' : 'ADMIN_USERNAME=' . $originalUsername);
            putenv($originalHash === false ? 'ADMIN_PASSWORD_HASH' : 'ADMIN_PASSWORD_HASH=' . $originalHash);
        }
    }

    public function testAdminAuthenticationDoesNotOpenOrAlterPhpSession(): void
    {
        $before = session_status(); $id = session_id();
        $this->assertNull($this->auth->getUser());
        $this->authenticate();
        $this->assertSame($before, session_status());
        $this->assertSame($id, session_id());
    }

    public function testSessionEndpointIsPublicAndDescribesJwtWithoutCsrfOrCookieIdentity(): void
    {
        $result = $this->controller()->runAction('session');
        $this->assertNull($result['user']); $this->assertSame('jwt', $result['authMode']);
        $this->assertArrayNotHasKey('csrfToken', $result);
        $this->assertSame('json', $this->app->response->format);
        $this->assertSame('no-store', $this->app->response->headers->get('Cache-Control'));
    }

    public function testProtectedEndpointRejectsWechatIdentityWithoutAdminSession(): void
    {
        $this->app->session->set('__id', 123);
        $this->expectException(HttpException::class);
        try {
            $this->controller()->runAction('devices');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->statusCode);
            throw $e;
        }
    }

    public function testLoginAcceptsJsonCredentialsWithoutCsrfAndReturnsJwt(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->app->request->setBodyParams(['username' => 'test-admin', 'password' => 'test-only-password']);
        $result = $this->controller()->runAction('login');
        $this->assertSame('admin', $result['user']['role']); $this->assertSame('jwt', $result['authMode']);
        $this->assertNotEmpty($result['token']['accessToken']); $this->assertArrayNotHasKey('csrfToken', $result);
    }

    public function testRefreshEndpointAtomicallyRotatesTheSuppliedRefreshToken(): void
    {
        $initial = $this->authenticate(); $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->app->request->setBodyParams(['refreshToken' => $initial['token']['refreshToken']]);
        $result = $this->controller()->runAction('refresh');
        $this->assertSame($initial['user'], $result['user']);
        $this->assertNotSame($initial['token']['refreshToken'], $result['token']['refreshToken']);
        $this->assertSame($initial['token']['refreshExpires'], $result['token']['refreshExpires']);
        $this->expectException(HttpException::class); $this->controller()->runAction('refresh');
    }

    public function testLogoutUsesPostAndRefreshTokenAndRevokesTheFamily(): void
    {
        $initial = $this->authenticate(); $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->app->request->setBodyParams(['refreshToken' => $initial['token']['refreshToken']]);
        $result = $this->controller()->runAction('logout');
        $this->assertNull($result['user']); $this->assertSame('jwt', $result['authMode']);
        $this->expectException(HttpException::class); $this->auth->getUser();
    }

    public function testReadingDevicesDoesNotCreateSetupOrInventOnlineState(): void
    {
        $this->authenticate();
        $result = $this->controller()->runAction('devices');
        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('保留标题', $result[0]['name']);
        $this->assertSame('unknown', $result[0]['status']);
        $this->assertSame(638, $result[0]['gameId']);
        $this->assertSame('device-b', $result[1]['name']);
        $this->assertNull($result[1]['gameId']);
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM setup')->queryScalar());
    }

    public function testReadingDevicesIncludesContentAndEmptyListsWithoutCreatingSetup(): void
    {
        $this->authenticate();
        $devices = $this->devices->getDevices();
        $this->assertSame(['保留口号'], $devices[0]['slogans']);
        $this->assertSame(['keep.png'], $devices[0]['pictures']);
        $this->assertSame(['keep.webp'], $devices[0]['thumbs']);
        foreach (['slogans', 'pictures', 'thumbs'] as $field) {
            $this->assertSame([], $devices[1][$field]);
        }
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM setup')->queryScalar());
    }

    public function testDeviceNamePrefersConfiguredTitleAndFallsBackForDefaultOrBlankTitle(): void
    {
        $this->authenticate();
        foreach ([['  新设备名称  ', '新设备名称'], ['未知', '大厅一号'], [' 未知 ', '大厅一号'], ['', '大厅一号'], ['  ', '大厅一号'], [null, '大厅一号']] as [$title, $name]) {
            $this->app->db->createCommand()->update('setup', ['title' => $title], ['device_id' => 1])->execute();
            $device = $this->devices->getDevices()[0];
            $this->assertSame((string) $title, $device['title']);
            $this->assertSame($name, $device['name']);
        }
        $missingSetup = $this->devices->getDevices()[1];
        $this->assertSame('', $missingSetup['title']);
        $this->assertSame('device-b', $missingSetup['name']);
    }

    public function testConfigurationTitleOnlySavePreservesSceneContentAndPhysicalDevice(): void
    {
        $this->authenticate();
        $this->catalog->loader = static function () { throw new \RuntimeException('Catalog must not be requested.'); };
        $beforeDevice = $this->app->db->createCommand('SELECT * FROM device WHERE id = 1')->queryOne();
        $beforeSetup = $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne();
        $result = $this->devices->updateConfiguration(1, ['title' => '  新设备名称  ']);
        $this->assertSame('新设备名称', $result['title']);
        $this->assertSame('新设备名称', $result['name']);
        $afterSetup = $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne();
        foreach (['scene_id', 'money', 'slogans', 'pictures', 'thumbs', 'shots', 'device_id', 'id'] as $field) {
            $this->assertSame($beforeSetup[$field], $afterSetup[$field], $field . ' must be preserved');
        }
        $this->assertSame($beforeDevice, $this->app->db->createCommand('SELECT * FROM device WHERE id = 1')->queryOne());
        $this->assertSame($result, $this->devices->getDevices()[0]);
    }

    public function testConfigurationClearingTitleRestoresPhysicalLabelFallback(): void
    {
        $this->authenticate();
        $result = $this->devices->updateConfiguration(1, ['title' => '  ']);
        $this->assertSame('', $result['title']);
        $this->assertSame('大厅一号', $result['name']);
        $this->assertSame('', Setup::findOne(['device_id' => 1])->title);
    }

    public function testConfigurationTitleSupports255UnicodeCharacters(): void
    {
        $this->authenticate();
        $title = str_repeat('名', 255);
        $result = $this->devices->updateConfiguration(1, ['title' => ' ' . $title . ' ']);
        $this->assertSame($title, $result['title']);
        $this->assertSame($title, $result['name']);
        $this->assertSame($title, Setup::findOne(['device_id' => 1])->title);
    }

    public function testConfigurationRejectsInvalidTitleWithoutChangingAnyFields(): void
    {
        $this->authenticate();
        $before = $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne();
        foreach ([null, 42, false, [], ['name'], str_repeat('名', 256), str_repeat('a', 256)] as $title) {
            try {
                $this->devices->updateConfiguration(1, ['title' => $title, 'slogans' => []]);
                $this->fail('Invalid title must not save any fields.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->statusCode);
            }
            $this->assertSame($before, $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne());
        }
    }

    public function testConfigurationTitleOnlyCreationPreservesDynamicDefaults(): void
    {
        $this->authenticate();
        $result = $this->devices->updateConfiguration(2, ['title' => '新设备']);
        $this->assertSame('新设备', $result['title']);
        $this->assertSame('新设备', $result['name']);
        $this->assertSame(638, $result['gameId']);
        $this->assertSame(Setup::DefaultData()['slogans'], $result['slogans']);
        $this->assertSame(Setup::DefaultInfo()['pictures'], $result['pictures']);
    }

    public function testConfigurationRejectsFourByteTitlesOnMysqlUtf8BeforeConnecting(): void
    {
        $this->authenticate();
        $fixtureDb = $this->app->db;
        try {
            foreach (['utf8', 'utf8mb3'] as $charset) {
                // A missing local socket prevents any network access if validation regresses.
                $mysql = new \yii\db\Connection([
                    'dsn' => 'mysql:unix_socket=' . $this->runtime . '/missing-mysql.sock;dbname=unused',
                    'charset' => $charset,
                ]);
                $this->app->set('db', $mysql);
                try {
                    $this->devices->updateConfiguration(1, ['title' => '大厅😀']);
                    $this->fail('Four-byte names must be rejected before a utf8 MySQL write.');
                } catch (HttpException $e) {
                    $this->assertSame(422, $e->statusCode);
                    $this->assertSame('设备名称暂不支持表情符号，请使用文字或数字', $e->getMessage());
                }
                $this->assertFalse($mysql->isActive);
            }
        } finally {
            $this->app->set('db', $fixtureDb);
        }
        $this->assertSame('大厅😀', $this->devices->updateConfiguration(1, ['title' => '大厅😀'])['title']);
    }

    public function testReadingLegacyContentNormalizesNullAndMalformedLists(): void
    {
        $this->authenticate();
        $this->app->db->createCommand()->update('setup', [
            'slogans' => null, 'pictures' => [' https://example.com/a.png ', '', null, 7],
            'thumbs' => ['legacy-key' => 'https://example.com/a.webp'],
        ], ['device_id' => 1])->execute();
        $device = $this->devices->getDevices()[0];
        $this->assertSame([], $device['slogans']);
        $this->assertSame(['https://example.com/a.png'], $device['pictures']);
        $this->assertSame(['https://example.com/a.webp'], $device['thumbs']);
    }

    public function testConfigurationSavesContentAndPreservesUneditedFieldsWithoutCatalog(): void
    {
        $this->authenticate();
        $this->catalog->loader = static function () { throw new \RuntimeException('Catalog must not be requested.'); };
        $before = $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne();
        $result = $this->devices->updateConfiguration(1, [
            'slogans' => ['  欢迎来到这里  ', '', '   ', '第二行'],
            'pictures' => [' https://example.com/a.png ', ''],
        ]);
        $this->assertSame(['欢迎来到这里', '第二行'], $result['slogans']);
        $this->assertSame(['https://example.com/a.png'], $result['pictures']);
        $this->assertSame(['keep.webp'], $result['thumbs']);
        $after = $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne();
        foreach (['scene_id', 'title', 'money', 'thumbs', 'shots', 'device_id', 'id'] as $field) {
            $this->assertSame($before[$field], $after[$field], $field . ' must be preserved');
        }
        $this->assertSame('2026-10-11 15:00:00', $result['updatedAt']);
        $this->assertSame($result, $this->devices->getDevices()[0]);
    }

    public function testConfigurationContentSavePreservesUnknownAndNullScenes(): void
    {
        $this->authenticate();
        $this->catalog->loader = static function () { throw new \RuntimeException('Catalog must not be requested.'); };
        foreach ([999, null] as $scene) {
            $this->app->db->createCommand()->update('setup', ['scene_id' => $scene], ['device_id' => 1])->execute();
            $result = $this->devices->updateConfiguration(1, ['slogans' => ['欢迎']]);
            $this->assertSame($scene, $result['gameId']);
        }
    }

    public function testConfigurationExplicitEmptyListsClearContent(): void
    {
        $this->authenticate();
        $result = $this->devices->updateConfiguration(1, ['slogans' => [], 'pictures' => [], 'thumbs' => []]);
        foreach (['slogans', 'pictures', 'thumbs'] as $field) {
            $this->assertSame([], $result[$field]);
            $this->assertSame([], Setup::findOne(['device_id' => 1])->$field);
        }
        $this->assertSame(638, $result['gameId']);
    }

    public function testConfigurationCreatesMissingSetupWithDynamicProtocolDefaults(): void
    {
        $this->authenticate();
        $result = $this->devices->updateConfiguration(2, ['slogans' => ['新欢迎词']]);
        $setup = Setup::findOne(['device_id' => 2]);
        $this->assertSame(638, $result['gameId']);
        $this->assertSame(['新欢迎词'], $result['slogans']);
        $this->assertSame('未知', $setup->title);
        $this->assertSame(Setup::DefaultInfo()['pictures'], $result['pictures']);
        $this->assertSame(Setup::DefaultData()['thumbs'], $result['thumbs']);
        $this->assertEquals(0, $setup->money);
        $this->assertSame(Setup::DefaultData()['shots'], $setup->shots);
    }

    public function testConfigurationExplicitGameAndContentSaveTogether(): void
    {
        $this->authenticate();
        $result = $this->devices->updateConfiguration(1, ['gameId' => 700, 'thumbs' => [' new.webp ']]);
        $this->assertSame(700, $result['gameId']);
        $this->assertSame(['new.webp'], $result['thumbs']);
        $this->assertSame(['保留口号'], $result['slogans']);
        $this->assertSame(['keep.png'], $result['pictures']);
    }

    public function testConfigurationExplicitGameDoesNotFetchAnUnneededDefault(): void
    {
        Yii::$container->setSingleton(AdminGameCatalog::class, new AdminGameCatalog([
            'cache' => new ArrayCache(),
            'loader' => static function () { throw new \RuntimeException('Default scene should not be requested.'); },
        ]));
        $this->authenticate();
        $this->assertSame(700, $this->devices->updateConfiguration(2, ['gameId' => 700, 'slogans' => []])['gameId']);
    }

    public function testConfigurationRejectsInvalidPatchWithoutChangingSetup(): void
    {
        $this->authenticate();
        $before = $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne();
        foreach ([
            [], ['tag' => '禁止修改物理标签'], ['slogans' => [], 'money' => 0],
            ['gameId' => '700'], ['gameId' => 0], ['gameId' => -1], ['gameId' => null], ['gameId' => 999],
            ['slogans' => '欢迎'], ['pictures' => null], ['thumbs' => [1]],
            ['slogans' => ['ok', false]], ['pictures' => [['nested']]],
            ['thumbs' => ['key' => 'a.webp']], ['slogans' => [1 => 'not a list']],
        ] as $patch) {
            try {
                $this->devices->updateConfiguration(1, $patch);
                $this->fail('Malformed patches must be rejected: ' . json_encode($patch));
            } catch (HttpException $e) {
                $this->assertSame(422, $e->statusCode);
            }
            $this->assertSame($before, $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne());
        }
    }

    public function testConfigurationExplicitUnchangedGameStillValidatesCatalog(): void
    {
        $this->authenticate();
        $this->catalog->loader = static function () { return []; };
        try {
            $this->devices->updateConfiguration(1, ['gameId' => 638, 'slogans' => ['不保存']]);
            $this->fail('An explicitly supplied game must be in the current catalog.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->statusCode);
        }
        $this->assertSame(['保留口号'], Setup::findOne(['device_id' => 1])->slogans);
    }

    public function testConfigurationDatabaseFailureRollsBackAllContent(): void
    {
        $this->authenticate();
        $before = $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne();
        $this->app->db->pdo->exec("CREATE TRIGGER reject_content AFTER UPDATE ON setup BEGIN SELECT RAISE(FAIL, 'private-content-error'); END");
        try {
            $this->devices->updateConfiguration(1, ['gameId' => 700, 'title' => '回滚名称', 'slogans' => ['回滚'], 'pictures' => [], 'thumbs' => []]);
            $this->fail('Failed writes must roll back.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->statusCode);
            $this->assertStringNotContainsString('private-content-error', $e->getMessage());
        }
        $this->assertSame($before, $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne());
    }

    public function testConfigurationDeviceManagerCannotUpdateUnownedOrRevokedDevices(): void
    {
        $this->app->db->createCommand('CREATE TABLE user (id INTEGER PRIMARY KEY)')->execute();
        $this->app->db->createCommand('CREATE TABLE control (id INTEGER PRIMARY KEY, user_id INTEGER, device_id INTEGER)')->execute();
        $this->app->db->createCommand()->insert('user', ['id' => 42])->execute();
        $this->app->db->createCommand()->insert('control', ['id' => 1, 'user_id' => 42, 'device_id' => 1])->execute();
        $login = $this->auth->loginWechat(42);
        $this->app->request->headers->set('Authorization', 'Bearer ' . $login['token']['accessToken']);
        $this->assertSame(['自己的欢迎词'], $this->devices->updateConfiguration(1, ['slogans' => ['自己的欢迎词']])['slogans']);
        foreach ([2, 999] as $deviceId) {
            try {
                $this->devices->updateConfiguration($deviceId, ['slogans' => []]);
                $this->fail('Unowned devices must not be editable.');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->statusCode);
            }
        }
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM setup')->queryScalar());
        $this->app->db->createCommand()->delete('control', ['user_id' => 42])->execute();
        try {
            $this->devices->updateConfiguration(1, ['slogans' => []]);
            $this->fail('Revoked control must take effect immediately.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->statusCode);
        }
        $this->assertSame(['自己的欢迎词'], Setup::findOne(['device_id' => 1])->slogans);
    }

    public function testConfigurationCookieOrCsrfWithoutBearerCannotModifyDevice(): void
    {
        $this->app->session->set('game.admin.identity', ['username' => 'test-admin', 'expiresAt' => time() + 28800]);
        $this->app->request->headers->set('X-CSRF-Token', 'unrelated-cookie-token');
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->setBodyParams(['slogans' => []]);
        try {
            $this->controller()->runAction('set-configuration', ['id' => 1]);
            $this->fail('Configuration writes must require an administrator Bearer token.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->statusCode);
        }
        $this->assertSame(['保留口号'], Setup::findOne(['device_id' => 1])->slogans);
    }

    public function testAuthenticatedConfigurationPutReturnsUpdatedDevice(): void
    {
        $this->authenticate();
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->setBodyParams(['slogans' => ['保存欢迎词'], 'pictures' => []]);
        $result = $this->controller()->runAction('set-configuration', ['id' => 1]);
        $this->assertSame(['保存欢迎词'], $result['slogans']);
        $this->assertSame([], $result['pictures']);
    }

    public function testConfigurationRejectsJsonObjectsInPlaceOfLists(): void
    {
        $this->authenticate();
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->headers->set('Content-Type', 'application/json');
        foreach (['{"slogans":{}}', '{"pictures":{"0":"a.png"}}', '[]', 'null'] as $json) {
            $this->app->request->setRawBody($json);
            $this->app->request->setBodyParams(null);
            try {
                $this->controller()->runAction('set-configuration', ['id' => 1]);
                $this->fail('JSON objects must not be accepted as lists: ' . $json);
            } catch (HttpException $e) {
                $this->assertSame(422, $e->statusCode);
            }
        }
        $this->assertSame(['保留口号'], Setup::findOne(['device_id' => 1])->slogans);
        $this->assertSame(['keep.png'], Setup::findOne(['device_id' => 1])->pictures);
    }

    public function testConfigurationJsonWithCharsetStillRejectsObjectLists(): void
    {
        $this->authenticate();
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->headers->set('Content-Type', 'application/json; charset=utf-8');
        $this->app->request->setRawBody('{"slogans":{}}');
        try {
            $this->controller()->runAction('set-configuration', ['id' => 1]);
            $this->fail('A content-type charset must not allow an object to clear a list.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->statusCode);
        }
        $this->assertSame(['保留口号'], Setup::findOne(['device_id' => 1])->slogans);
    }

    public function testConfigurationAcceptsRawJsonListsAndNormalizesContent(): void
    {
        $this->authenticate();
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->headers->set('Content-Type', 'application/json; charset=utf-8');
        $this->app->request->setRawBody('{"gameId":700,"title":" new name ","slogans":[" welcome ",""],"pictures":[],"thumbs":["a.webp"]}');
        $result = $this->controller()->runAction('set-configuration', ['id' => 1]);
        $this->assertSame('new name', $result['title']);
        $this->assertSame('new name', $result['name']);
        $this->assertSame(700, $result['gameId']);
        $this->assertSame(['welcome'], $result['slogans']);
        $this->assertSame([], $result['pictures']);
        $this->assertSame(['a.webp'], $result['thumbs']);
    }

    public function testConfigurationRequiresAuthenticationAndPut(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->setBodyParams(['slogans' => []]);
        try {
            $this->controller()->runAction('set-configuration', ['id' => 1]);
            $this->fail('Configuration writes must require an administrator Bearer token.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->statusCode);
        }
        $this->authenticate();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $this->controller()->runAction('set-configuration', ['id' => 1]);
            $this->fail('Configuration writes must use PUT.');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->statusCode);
        }
        $this->assertSame(['保留口号'], Setup::findOne(['device_id' => 1])->slogans);
    }

    public function testGameCatalogNormalizesWrappedVerseIdsAndNames(): void
    {
        $this->assertSame([
            ['id' => 638, 'name' => '星际漫游', 'description' => '', 'category' => ''],
            ['id' => 700, 'name' => '音乐派对', 'description' => '节奏游戏', 'category' => '音乐'],
        ], $this->catalog->getGames());
    }

    public function testCatalogAcceptsDirectListsAndNestedPagination(): void
    {
        foreach ([
            [['verse_id' => 638, 'name' => '星际漫游']],
            ['data' => ['items' => [['verse_id' => 638, 'name' => '星际漫游']]]],
        ] as $payload) {
            $catalog = new AdminGameCatalog(['cache' => new ArrayCache(), 'loader' => static function () use ($payload) { return $payload; }]);
            $this->assertSame(638, $catalog->getGames()[0]['id']);
        }
    }

    public function testCatalogFailureDoesNotLeakPrivateUpstreamDetails(): void
    {
        $catalog = new AdminGameCatalog(['cache' => new ArrayCache(), 'loader' => static function () { throw new \RuntimeException('private-api-key=secret'); }]);
        $this->expectException(HttpException::class);
        try {
            $catalog->getGames();
        } catch (HttpException $e) {
            $this->assertSame(503, $e->statusCode);
            $this->assertStringNotContainsString('private-api-key', $e->getMessage());
            $this->assertNull($e->getPrevious());
            throw $e;
        }
    }

    public function testDefaultSceneUsesFirstValidCheckinVerseInUpstreamOrder(): void
    {
        $this->catalog->loader = static function () {
            return ['data' => ['items' => [
                ['verse_id' => 0, 'name' => '无效编号'],
                ['verse_id' => 900, 'name' => ''],
                ['id' => 1, 'verse_id' => '912', 'name' => '第一个可用场景'],
                ['id' => 2, 'verse_id' => 638, 'name' => '后面的场景'],
            ]]];
        };

        $this->assertSame(912, Setup::DefaultInfo()['scene_id']);
    }

    public function testEmptyCheckinCatalogLeavesDefaultSceneUnassigned(): void
    {
        $this->catalog->loader = static function () { return []; };

        $this->assertNull(Setup::DefaultInfo()['scene_id']);
    }

    public function testNewDevicePersistsTheFirstAvailableCheckinScene(): void
    {
        $this->catalog->loader = static function () {
            return [['verse_id' => 912, 'name' => '默认场景'], ['verse_id' => 638, 'name' => '其他场景']];
        };

        $info = Server::getInfo('new-device');
        $this->assertSame(912, $info['scene_id']);
        $deviceId = $this->app->db->createCommand('SELECT id FROM device WHERE uuid = :uuid', [':uuid' => 'new-device'])->queryScalar();
        $setup = Setup::findOne(['device_id' => $deviceId]);
        $this->assertNotNull($setup);
        $this->assertSame(912, (int) $setup->scene_id);
        $this->assertSame('未知', $info['title']);
        $this->assertCount(4, $info['pictures']);
        $this->assertEquals(Setup::DefaultData(), $setup->getData());
    }

    public function testMissingSetupFallbackUsesTheFirstAvailableCheckinScene(): void
    {
        $this->catalog->loader = static function () {
            return [['verse_id' => 912, 'name' => '默认场景']];
        };

        $this->assertSame(912, Server::getInfo('device-b')['scene_id']);
    }

    public function testExistingDeviceKeepsItsConfiguredSceneWithoutFetchingDefaults(): void
    {
        $this->catalog->loader = static function () { throw new \RuntimeException('Upstream unavailable.'); };

        $this->assertSame(638, (int) Server::getInfo('device-a')['scene_id']);
    }

    public function testSceneServiceFailureDoesNotLeaveAPartiallyInitializedNewDevice(): void
    {
        $this->catalog->loader = static function () { throw new \RuntimeException('Upstream unavailable.'); };
        try {
            Server::getInfo('retryable-new-device');
            $this->fail('A scene service failure must not initialize a device with a fixed scene.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->statusCode);
        }
        $this->assertSame(0, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM device WHERE uuid = :uuid', [':uuid' => 'retryable-new-device'])->queryScalar());

        $this->catalog->loader = static function () { return [['verse_id' => 912, 'name' => '恢复后的场景']]; };
        $this->assertSame(912, Server::getInfo('retryable-new-device')['scene_id']);
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM setup WHERE scene_id = 912')->queryScalar());
    }

    public function testExplicitGameAssignmentDoesNotFetchAnUnneededDefaultScene(): void
    {
        Yii::$container->setSingleton(AdminGameCatalog::class, new AdminGameCatalog([
            'cache' => new ArrayCache(),
            'loader' => static function () { throw new \RuntimeException('Default scene should not be requested.'); },
        ]));
        $this->authenticate();

        $this->assertSame(700, $this->devices->assignGame(2, 700)['gameId']);
    }

    public function testInvalidGameDoesNotChangeExistingSetup(): void
    {
        $this->authenticate();
        try {
            $this->devices->assignGame(1, 999999);
            $this->fail('Unknown scene must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->statusCode);
        }
        $this->assertSame(638, (int) $this->app->db->createCommand('SELECT scene_id FROM setup WHERE device_id = 1')->queryScalar());
    }

    public function testAssigningGameUpdatesOnlySceneAndKeepsOtherSetupFields(): void
    {
        $this->authenticate();
        $before = $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne();
        $result = $this->devices->assignGame(1, 700);
        $this->assertSame(700, $result['gameId']);
        $after = $this->app->db->createCommand('SELECT * FROM setup WHERE device_id = 1')->queryOne();
        foreach (['title', 'money', 'slogans', 'pictures', 'thumbs', 'shots', 'device_id', 'id'] as $field) {
            $this->assertSame($before[$field], $after[$field], $field . ' must be preserved');
        }
    }

    public function testAssigningGameCreatesMissingSetupInTransaction(): void
    {
        $this->authenticate();
        $transaction = $this->app->db->getTransaction();
        $initialLevel = $transaction === null ? 0 : $transaction->level;
        $insertLevel = null;
        $observeInsert = function () use (&$insertLevel) {
            $activeTransaction = $this->app->db->getTransaction();
            $insertLevel = $activeTransaction === null ? 0 : $activeTransaction->level;
        };
        \yii\base\Event::on(Setup::class, Setup::EVENT_BEFORE_INSERT, $observeInsert);
        try {
            $result = $this->devices->assignGame(2, 700);
        } finally {
            \yii\base\Event::off(Setup::class, Setup::EVENT_BEFORE_INSERT, $observeInsert);
        }
        $this->assertSame($initialLevel + 1, $insertLevel, 'The service must open its own transaction around the insert.');
        $this->assertSame(700, $result['gameId']);
        $this->assertSame(2, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM setup')->queryScalar());
        // Codeception may own an outer transaction; the service must leave it intact.
        $this->assertSame($transaction, $this->app->db->getTransaction());
        $this->assertSame($initialLevel, $transaction === null ? 0 : $transaction->level);
    }

    public function testFirstAssignmentPreservesExistingDeviceProtocolDefaults(): void
    {
        $this->authenticate();
        $beforeInfo = Server::getInfo('device-b');
        $this->assertSame('未知', $beforeInfo['title']);
        $this->assertCount(4, $beforeInfo['pictures']);
        $this->devices->assignGame(2, 700);

        $afterInfo = Server::getInfo('device-b');
        $this->assertSame($beforeInfo['title'], $afterInfo['title']);
        $this->assertSame($beforeInfo['pictures'], $afterInfo['pictures']);
        $this->assertSame(700, $afterInfo['scene_id']);
        $setup = Setup::findOne(['device_id' => 2]);
        $this->assertNotNull($setup);
        $data = $setup->getData();
        $defaults = Setup::DefaultData();
        $this->assertEquals($defaults['money'], $data['money']);
        foreach (['slogans', 'thumbs', 'shots'] as $field) {
            $this->assertSame($defaults[$field], $data[$field]);
        }
        $this->assertSame('2026-10-11 15:00:00', $afterInfo['updated_at']);
    }

    public function testAssignmentReturnsPersistedTimestampConsistentWithNextRead(): void
    {
        $this->authenticate();
        foreach ([1, 2] as $deviceId) {
            $result = $this->devices->assignGame($deviceId, 700);
            $this->assertSame('2026-10-11 15:00:00', $result['updatedAt']);
            $subsequent = array_column($this->devices->getDevices(), null, 'id');
            $this->assertSame($subsequent[$deviceId]['updatedAt'], $result['updatedAt']);
        }
    }

    public function testDatabaseFailureRollsBackSetupAndDoesNotLeakSql(): void
    {
        $this->authenticate();
        $transaction = $this->app->db->getTransaction();
        $initialLevel = $transaction === null ? 0 : $transaction->level;
        // FAIL preserves an already-inserted row unless the service rolls back;
        // ABORT would undo the statement itself and hide a missing transaction.
        $this->app->db->pdo->exec("CREATE TRIGGER reject_setup AFTER INSERT ON setup BEGIN SELECT RAISE(FAIL, 'private-database-error'); END");
        try {
            $this->devices->assignGame(2, 700);
            $this->fail('Failed database writes must return an error.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->statusCode);
            $this->assertStringNotContainsString('private-database-error', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM setup')->queryScalar());
        $this->assertSame($transaction, $this->app->db->getTransaction());
        $this->assertSame($initialLevel, $transaction === null ? 0 : $transaction->level);
    }

    public function testUpdatingUnknownDeviceReturns404(): void
    {
        $this->authenticate();
        $this->expectException(HttpException::class);
        try {
            $this->devices->assignGame(999, 700);
        } catch (HttpException $e) {
            $this->assertSame(404, $e->statusCode);
            throw $e;
        }
    }

    public function testBearerMutationStillRequiresNumericJsonGameId(): void
    {
        $this->authenticate();
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->setBodyParams(['gameId' => '700']);
        $this->expectException(HttpException::class);
        try {
            $this->controller()->runAction('set-game', ['id' => 1]);
        } catch (HttpException $e) {
            $this->assertSame(422, $e->statusCode);
            throw $e;
        }
    }

    public function testCookieOrCsrfAloneCannotAuthorizeDeviceMutation(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->session->set('__id', 123);
        $this->app->request->headers->set('X-CSRF-Token', 'unrelated-cookie-token');
        $this->app->request->setBodyParams(['gameId' => 700]);
        try { $this->controller()->runAction('set-game', ['id' => 1]); $this->fail('Bearer is required.'); }
        catch (HttpException $e) { $this->assertSame(401, $e->statusCode); }
        $this->assertSame(638, (int) $this->app->db->createCommand('SELECT scene_id FROM setup WHERE device_id = 1')->queryScalar());
    }

    public function testAuthenticatedPutReturnsUpdatedDevice(): void
    {
        $this->authenticate();
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->setBodyParams(['gameId' => 700]);
        $result = $this->controller()->runAction('set-game', ['id' => 1]);
        $this->assertSame(1, $result['id']);
        $this->assertSame(700, $result['gameId']);
    }

    public function testPasswordAdministratorCanDeleteDeviceAndItsConfiguration(): void
    {
        $this->authenticate();
        $this->createDeletionRelations();
        $_SERVER['REQUEST_METHOD'] = 'DELETE';

        $this->assertSame(['id' => 1, 'deleted' => true], $this->controller()->runAction('delete-device', ['id' => 1]));

        foreach (['device' => 'id', 'setup' => 'device_id', 'control' => 'device_id', 'manager' => 'device_id'] as $table => $column) {
            $this->assertSame(0, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = 1')->queryScalar());
        }
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM device WHERE id = 2')->queryScalar());
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM control WHERE device_id = 2')->queryScalar());
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM manager WHERE device_id = 2')->queryScalar());
        $this->assertSame([2], array_column($this->devices->getDevices(), 'id'));
    }

    public function testPasswordAdministratorCanDeleteDeviceWithoutConfiguration(): void
    {
        $this->authenticate();
        $this->createDeletionRelations();
        $this->assertSame(['id' => 2, 'deleted' => true], $this->devices->deleteDevice(2));
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM setup WHERE device_id = 1')->queryScalar());
    }

    public function testAnonymousCannotDeleteDeviceThroughControllerOrService(): void
    {
        $this->createDeletionRelations();
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        foreach ([function () { $this->controller()->runAction('delete-device', ['id' => 1]); }, function () { $this->devices->deleteDevice(1); }] as $delete) {
            try { $delete(); $this->fail('Deletion requires an administrator Bearer token.'); }
            catch (HttpException $e) { $this->assertSame(401, $e->statusCode); }
        }
        $this->assertSame(2, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM device')->queryScalar());
    }

    public function testWechatDeviceManagerCannotDeleteOwnedOrOtherDevice(): void
    {
        $this->createDeletionRelations();
        $this->app->db->createCommand('CREATE TABLE user (id INTEGER PRIMARY KEY)')->execute();
        $this->app->db->createCommand()->insert('user', ['id' => 42])->execute();
        $login = $this->auth->loginWechat(42);
        $this->app->request->headers->set('Authorization', 'Bearer ' . $login['token']['accessToken']);
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        foreach ([1, 2, 999] as $deviceId) {
            try { $this->controller()->runAction('delete-device', ['id' => $deviceId]); $this->fail('A device manager cannot delete devices.'); }
            catch (HttpException $e) { $this->assertSame(403, $e->statusCode); }
        }
        $this->assertSame(2, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM device')->queryScalar());
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM setup')->queryScalar());
        $this->assertSame(2, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM control')->queryScalar());
    }

    public function testDeletingUnknownDeviceReturns404(): void
    {
        $this->authenticate();
        $this->createDeletionRelations();
        try { $this->devices->deleteDevice(999); $this->fail('Unknown devices must return 404.'); }
        catch (HttpException $e) { $this->assertSame(404, $e->statusCode); }
    }

    public function testDeviceDeletionFailureRollsBackConfigurationAndOwnership(): void
    {
        $this->authenticate();
        $this->createDeletionRelations();
        $this->app->db->pdo->exec("CREATE TRIGGER reject_device_delete AFTER DELETE ON device BEGIN SELECT RAISE(FAIL, 'private-delete-error'); END");
        try { $this->devices->deleteDevice(1); $this->fail('Failed deletion must return an error.'); }
        catch (HttpException $e) {
            $this->assertSame(503, $e->statusCode);
            $this->assertStringNotContainsString('private-delete-error', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        foreach (['device' => 'id', 'setup' => 'device_id', 'control' => 'device_id', 'manager' => 'device_id'] as $table => $column) {
            $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = 1')->queryScalar());
        }
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT device_id FROM record WHERE id = 1')->queryScalar());
        $this->assertNull($this->app->db->getTransaction());
    }

    public function testDeletingDeviceKeepsHistoricalRecordsAndOtherBusinessData(): void
    {
        $this->authenticate();
        $this->createDeletionRelations();
        $before = $this->app->db->createCommand('SELECT * FROM record ORDER BY id')->queryAll();
        $businessData = $this->app->db->createCommand('SELECT * FROM gain')->queryAll();
        $this->devices->deleteDevice(1);
        $after = $this->app->db->createCommand('SELECT * FROM record ORDER BY id')->queryAll();
        $before[0]['device_id'] = null;
        $this->assertSame($before, $after);
        $this->assertSame($businessData, $this->app->db->createCommand('SELECT * FROM gain')->queryAll());
        $record = \app\modules\v1\models\Record::findOne(1);
        $this->assertNull($record->device);
        $record->status = 'finish';
        $this->assertTrue($record->validate(['device_id']), json_encode($record->errors));
        $this->assertTrue($record->save(false, ['status']));
        $this->assertSame('finish', $this->app->db->createCommand('SELECT status FROM record WHERE id = 1')->queryScalar());
        $newRecord = new \app\modules\v1\models\Record();
        $this->assertFalse($newRecord->validate(['device_id']));
        $this->assertTrue($newRecord->hasErrors('device_id'));
    }

    public function testLegacyNotNullRecordSchemaRejectsDeletionAndRollsBackEverything(): void
    {
        $this->authenticate();
        $this->createDeletionRelations(false);
        $before = $this->app->db->createCommand('SELECT * FROM record ORDER BY id')->queryAll();
        try { $this->devices->deleteDevice(1); $this->fail('A device with historical records must not be deleted before migration.'); }
        catch (HttpException $e) { $this->assertSame(503, $e->statusCode); }
        $this->assertSame($before, $this->app->db->createCommand('SELECT * FROM record ORDER BY id')->queryAll());
        foreach (['device' => 'id', 'setup' => 'device_id', 'control' => 'device_id', 'manager' => 'device_id'] as $table => $column) {
            $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = 1')->queryScalar());
        }
    }

    public function testUnfinishedGamePreventsDeviceDeletionWithoutChangingAnyData(): void
    {
        $this->authenticate();
        $this->createDeletionRelations();
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        foreach (['ready', 'running'] as $status) {
            $this->app->db->createCommand()->update('record', ['status' => $status], ['id' => 1])->execute();
            $before = [];
            foreach (['device', 'setup', 'control', 'manager', 'record', 'gain'] as $table) {
                $before[$table] = $this->app->db->createCommand('SELECT * FROM ' . $table . ' ORDER BY id')->queryAll();
            }
            try { $this->controller()->runAction('delete-device', ['id' => 1]); $this->fail('Unfinished games must prevent deletion.'); }
            catch (HttpException $e) {
                $this->assertSame(409, $e->statusCode);
                $this->assertSame('设备仍有未结束的游戏，请先结束游戏后再删除', $e->getMessage());
            }
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, $this->app->db->createCommand('SELECT * FROM ' . $table . ' ORDER BY id')->queryAll(), $table);
            }
        }
    }

    public function testDeviceDeletionOnlyAcceptsDeleteMethod(): void
    {
        $this->authenticate();
        foreach (['GET', 'POST', 'PUT'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;
            try { $this->controller()->runAction('delete-device', ['id' => 1]); $this->fail('Deletion must require DELETE.'); }
            catch (HttpException $e) { $this->assertSame(405, $e->statusCode); }
        }
        $this->assertSame(2, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM device')->queryScalar());
    }

    public function testDeploymentRoutesResolveAllAdminEndpoints(): void
    {
        $configuration = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        $managerConfiguration = $configuration['components']['urlManager'];
        $managerConfiguration['cache'] = false;
        $manager = Yii::createObject($managerConfiguration);
        foreach ([
            ['GET', 'admin-api/session', 'admin/session', []],
            ['POST', 'admin-api/login', 'admin/login', []],
            ['POST', 'admin-api/logout', 'admin/logout', []],
            ['POST', 'admin-api/refresh', 'admin/refresh', []],
            ['GET', 'admin-api/devices', 'admin/devices', []],
            ['GET', 'admin-api/games', 'admin/games', []],
            ['DELETE', 'admin-api/devices/42', 'admin/delete-device', ['id' => '42']],
            ['PUT', 'admin-api/devices/42/game', 'admin/set-game', ['id' => '42']],
            ['PUT', 'admin-api/devices/42/configuration', 'admin/set-configuration', ['id' => '42']],
        ] as $route) {
            $_SERVER['REQUEST_METHOD'] = $route[0];
            $this->app->request->setPathInfo($route[1]);
            $this->assertSame([$route[2], $route[3]], $manager->parseRequest($this->app->request));
        }
    }

    public function testAllAdminRoutesSkipUnavailableCacheBeforeRequestDispatch(): void
    {
        $configuration = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        $cache = new AdminUnavailableCache();
        $this->app->set('cache', $cache);
        foreach (['admin-api', 'admin-api/session', 'admin-api/login', 'admin-api/logout', 'admin-api/devices', 'admin-api/games', 'admin-api/devices/1/game', 'admin-api/devices/1/configuration'] as $path) {
            $this->app->request->setPathInfo($path);
            $manager = Yii::createObject($configuration['components']['urlManager']);
            $this->assertFalse($manager->cache, $path);
        }
        $this->assertSame(0, $cache->reads);
        $this->assertSame(0, $cache->writes);
    }

    public function testOrdinaryRoutesStillUseConfiguredCache(): void
    {
        $configuration = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        $cache = new ArrayCache();
        $this->app->set('cache', $cache);
        $this->app->request->setPathInfo('swagger');
        $manager = Yii::createObject($configuration['components']['urlManager']);
        $this->assertSame($cache, $manager->cache);
    }

    public function testRealDebugAndGiiBootstrapKeepsAdminAndHealthRoutesAvailable(): void
    {
        $configuration = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        $cache = new AdminUnavailableCache();
        $this->app->set('cache', $cache);
        $this->app->set('user', ['class' => 'yii\\web\\User', 'identityClass' => 'app\\modules\\v2\\models\\User', 'enableAutoLogin' => false]);
        // Simulate a returning browser; UserPanel then reads its session identity.
        $this->app->session->setHasSessionId(true);
        try {
            foreach ([['GET', 'admin-api/session', 'admin/session'], ['GET', 'health', 'health/index'], ['HEAD', 'health', 'health/index']] as $case) {
                $_SERVER['REQUEST_METHOD'] = $case[0];
                $this->app->request->setPathInfo($case[1]);
                $this->app->set('urlManager', $configuration['components']['urlManager']);
                $this->app->setModule('debug', ['class' => 'yii\\debug\\Module']);
                $this->app->setModule('gii', ['class' => 'yii\\gii\\Module']);
                $this->app->getModule('debug')->bootstrap($this->app);
                $this->app->getModule('gii')->bootstrap($this->app);
                $this->assertTrue($this->app->session->isActive, 'The real debug UserPanel opens the session before dispatch.');
                $this->assertFalse($this->app->urlManager->cache);
                $this->assertSame([$case[2], []], $this->app->urlManager->parseRequest($this->app->request));
            }
            $this->assertSame(0, $cache->reads);
            $this->assertSame(0, $cache->writes);
        } finally {
            $this->app->getLog()->targets = [];
        }
    }

    public function testErrorHandlerKeepsCsrfAndAuthenticationErrorsAsJsonWithSiteErrorConfigured(): void
    {
        $this->app->errorHandler->errorAction = 'site/error';
        foreach ([['POST', 'login', 422], ['GET', 'devices', 401]] as $case) {
            $_SERVER['REQUEST_METHOD'] = $case[0];
            $response = $this->app->response;
            $response->format = 'html';
            $response->isSent = false;
            try {
                $this->controller()->runAction($case[1]);
                $this->fail('Endpoint should have failed.');
            } catch (HttpException $exception) {
                $renderer = new \ReflectionMethod($this->app->errorHandler, 'renderException');
                ob_start();
                try {
                    $renderer->invoke($this->app->errorHandler, $exception);
                    $body = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $this->assertSame($case[2], $response->statusCode);
                $this->assertSame('application/json; charset=UTF-8', $response->headers->get('Content-Type'));
                $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame($case[2], $json['status']);
                $this->assertNotEmpty($json['message']);
                $this->assertArrayNotHasKey('stack-trace', $json);
            }
        }
    }

    private function authenticate(): array
    {
        $result = $this->auth->login('test-admin', 'test-only-password');
        $this->app->request->headers->set('Authorization', 'Bearer ' . $result['token']['accessToken']);
        return $result;
    }

    private function createDeletionRelations(bool $nullableRecordDevice = true): void
    {
        $this->app->db->createCommand('PRAGMA foreign_keys = ON')->execute();
        $this->app->db->createCommand('CREATE TABLE control (id INTEGER PRIMARY KEY, user_id INTEGER, device_id INTEGER)')->execute();
        $this->app->db->createCommand('CREATE TABLE manager (id INTEGER PRIMARY KEY, device_id INTEGER)')->execute();
        $this->app->db->createCommand('CREATE TABLE record (id INTEGER PRIMARY KEY, device_id INTEGER ' . ($nullableRecordDevice ? '' : 'NOT NULL ') . 'REFERENCES device(id) ON DELETE CASCADE, player_id INTEGER, status TEXT, game JSON, created_at TEXT, updated_at TEXT)')->execute();
        $this->app->db->createCommand('CREATE TABLE gain (id INTEGER PRIMARY KEY, points INTEGER)')->execute();
        $this->app->db->createCommand()->batchInsert('control', ['id', 'user_id', 'device_id'], [[1, 42, 1], [2, 43, 2]])->execute();
        $this->app->db->createCommand()->batchInsert('manager', ['id', 'device_id'], [[1, 1], [2, 2]])->execute();
        $this->app->db->createCommand()->batchInsert('record', ['id', 'device_id', 'player_id', 'status', 'game'], [[1, 1, 11, 'finish', '{"points":30}'], [2, 2, 12, 'finish', '{"points":90}']])->execute();
        $this->app->db->createCommand()->insert('gain', ['id' => 1, 'points' => 900])->execute();
    }

    private function controller(): AdminController
    {
        return new AdminController('admin', $this->app, ['auth' => $this->auth, 'devices' => $this->devices, 'catalog' => $this->catalog]);
    }
}

class AdminUnavailableCache extends ArrayCache
{
    public int $reads = 0;
    public int $writes = 0;

    protected function getValue($key)
    {
        $this->reads++;
        throw new \RuntimeException('Optional cache is unavailable.');
    }

    protected function setValue($key, $value, $duration)
    {
        $this->writes++;
        throw new \RuntimeException('Optional cache is unavailable.');
    }
}

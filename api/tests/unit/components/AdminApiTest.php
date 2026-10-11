<?php

namespace tests\unit\components;

use app\components\AdminAuthService;
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
                    'scriptFile' => dirname(__DIR__, 3) . '/web/index.php',
                    'scriptUrl' => '/index.php',
                ],
                'session' => [
                    'class' => 'yii\web\Session',
                    'savePath' => $this->runtime,
                    'useCookies' => false,
                ],
                'db' => ['class' => 'yii\db\Connection', 'dsn' => 'sqlite::memory:'],
                'cache' => ['class' => ArrayCache::class],
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
        $db->createCommand('CREATE TABLE device (id INTEGER PRIMARY KEY, uuid TEXT, tag TEXT, ip TEXT, created_at TEXT, updated_at TEXT)')->execute();
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
        $this->devices = new AdminDeviceService(['catalog' => $this->catalog]);
    }

    protected function tearDown(): void
    {
        $this->app->session->destroy();
        $this->app->session->close();
        $this->app->db->close();
        $this->app->errorHandler->unregister();
        Yii::$app = $this->originalApp;
        Yii::$aliases = $this->originalAliases;
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

    public function testLoginRotatesSessionAndLogoutClearsOnlyAdminIdentity(): void
    {
        $this->app->session->set('wechat-user', 42);
        $before = $this->app->session->id;
        $this->assertSame(['username' => 'test-admin'], $this->auth->login('test-admin', 'test-only-password'));
        $this->assertNotSame($before, $this->app->session->id);
        $this->assertSame(['username' => 'test-admin'], $this->auth->getUser());
        $this->auth->logout();
        $this->assertNull($this->auth->getUser());
        $this->assertSame(42, $this->app->session->get('wechat-user'));
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
            $this->auth->login('test-admin', 'test-only-password');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->statusCode);
            throw $e;
        }
    }

    public function testExpiredAdminSessionIsRejected(): void
    {
        $this->auth->login('test-admin', 'test-only-password');
        $identity = $this->app->session->get(AdminAuthService::SESSION_KEY);
        $identity['expiresAt'] = time() - 1;
        $this->app->session->set(AdminAuthService::SESSION_KEY, $identity);
        $this->assertNull($this->auth->getUser());
        $this->assertFalse($this->app->session->has(AdminAuthService::SESSION_KEY));
    }

    public function testAdminSessionHasAnEightHourAbsoluteDeadline(): void
    {
        $now = time();
        $this->auth->login('test-admin', 'test-only-password');
        $identity = $this->app->session->get(AdminAuthService::SESSION_KEY);
        $this->assertGreaterThanOrEqual($now + 28800, $identity['expiresAt']);
        $this->assertLessThanOrEqual(time() + 28800, $identity['expiresAt']);
    }

    public function testAuthenticationDoesNotNeedTheApplicationRedisCache(): void
    {
        $this->app->set('cache', ['class' => 'yii\\redis\\Cache', 'redis' => ['class' => 'yii\\redis\\Connection', 'hostname' => 'unavailable.invalid']]);
        $auth = new AdminAuthService(['username' => 'test-admin', 'passwordHash' => $this->auth->passwordHash]);
        $this->assertSame(['username' => 'test-admin'], $auth->login('test-admin', 'test-only-password'));
    }

    public function testCredentialsCanBeOverriddenByEnvironment(): void
    {
        $originalUsername = getenv('ADMIN_USERNAME');
        $originalHash = getenv('ADMIN_PASSWORD_HASH');
        putenv('ADMIN_USERNAME=environment-admin');
        putenv('ADMIN_PASSWORD_HASH=' . password_hash('environment-test-password', PASSWORD_DEFAULT));
        try {
            $auth = new AdminAuthService(['cache' => new ArrayCache()]);
            $this->assertSame(['username' => 'environment-admin'], $auth->login('environment-admin', 'environment-test-password'));
        } finally {
            putenv($originalUsername === false ? 'ADMIN_USERNAME' : 'ADMIN_USERNAME=' . $originalUsername);
            putenv($originalHash === false ? 'ADMIN_PASSWORD_HASH' : 'ADMIN_PASSWORD_HASH=' . $originalHash);
        }
    }

    public function testAlreadyStartedSessionReceivesSecureCookiesWithoutLosingBootstrapData(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $session = $this->app->session;
        $session->setUseCookies(true);
        $session->setTimeout(60);
        $session->setUseStrictMode(false);
        $session->setCookieParams(['secure' => false, 'httponly' => false, 'samesite' => '']);
        $session->set('bootstrap-data', 'keep-me');
        $this->assertTrue($session->isActive);

        $this->assertNull($this->auth->getUser());

        $actualParameters = session_get_cookie_params();
        $this->assertTrue($actualParameters['secure']);
        $this->assertTrue($actualParameters['httponly']);
        $this->assertSame('Lax', $actualParameters['samesite']);
        $this->assertSame(28800, $session->getTimeout());
        $this->assertTrue($session->getUseStrictMode());
        $this->assertSame('keep-me', $session->get('bootstrap-data'));
    }

    public function testSessionEndpointReturnsCsrfAndNoIdentityBeforeLogin(): void
    {
        $result = $this->controller()->runAction('session');
        $this->assertNull($result['user']);
        $this->assertNotEmpty($result['csrfToken']);
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

    public function testLoginRequiresCsrfEvenWithValidCredentials(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->app->request->setBodyParams(['username' => 'test-admin', 'password' => 'test-only-password']);
        $this->expectException(BadRequestHttpException::class);
        $this->controller()->runAction('login');
    }

    public function testLoginAcceptsCsrfHeaderAndReturnsFreshCsrf(): void
    {
        $initial = $this->controller()->runAction('session');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->app->request->headers->set('X-CSRF-Token', $initial['csrfToken']);
        $this->app->request->setBodyParams(['username' => 'test-admin', 'password' => 'test-only-password']);
        $result = $this->controller()->runAction('login');
        $this->assertSame(['username' => 'test-admin'], $result['user']);
        $this->assertNotSame($initial['csrfToken'], $result['csrfToken']);
    }

    public function testLogoutUsesPostAndCsrfAndClearsAuthentication(): void
    {
        $this->auth->login('test-admin', 'test-only-password');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $token = $this->app->request->getCsrfToken();
        $this->app->request->headers->set('X-CSRF-Token', $token);
        $result = $this->controller()->runAction('logout');
        $this->assertNull($result['user']);
        $this->assertNull($this->auth->getUser());
        $this->assertNotSame($token, $result['csrfToken']);
    }

    public function testReadingDevicesDoesNotCreateSetupOrInventOnlineState(): void
    {
        $this->auth->login('test-admin', 'test-only-password');
        $result = $this->controller()->runAction('devices');
        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('大厅一号', $result[0]['name']);
        $this->assertSame('unknown', $result[0]['status']);
        $this->assertSame(638, $result[0]['gameId']);
        $this->assertSame('device-b', $result[1]['name']);
        $this->assertNull($result[1]['gameId']);
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM setup')->queryScalar());
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

    public function testInvalidGameDoesNotChangeExistingSetup(): void
    {
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
        foreach ([1, 2] as $deviceId) {
            $result = $this->devices->assignGame($deviceId, 700);
            $this->assertSame('2026-10-11 15:00:00', $result['updatedAt']);
            $subsequent = array_column($this->devices->getDevices(), null, 'id');
            $this->assertSame($subsequent[$deviceId]['updatedAt'], $result['updatedAt']);
        }
    }

    public function testDatabaseFailureRollsBackSetupAndDoesNotLeakSql(): void
    {
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
        $this->expectException(HttpException::class);
        try {
            $this->devices->assignGame(999, 700);
        } catch (HttpException $e) {
            $this->assertSame(404, $e->statusCode);
            throw $e;
        }
    }

    public function testUpdatingGameRequiresNumericJsonIdAndCsrf(): void
    {
        $this->auth->login('test-admin', 'test-only-password');
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->headers->set('X-CSRF-Token', $this->app->request->getCsrfToken());
        $this->app->request->setBodyParams(['gameId' => '700']);
        $this->expectException(HttpException::class);
        try {
            $this->controller()->runAction('set-game', ['id' => 1]);
        } catch (HttpException $e) {
            $this->assertSame(422, $e->statusCode);
            throw $e;
        }
    }

    public function testUpdatingGameWithoutCsrfDoesNotModifyDevice(): void
    {
        $this->auth->login('test-admin', 'test-only-password');
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->setBodyParams(['gameId' => 700]);
        try {
            $this->controller()->runAction('set-game', ['id' => 1]);
            $this->fail('CSRF must protect updates.');
        } catch (BadRequestHttpException $e) {
            $this->assertSame(400, $e->statusCode);
        }
        $this->assertSame(638, (int) $this->app->db->createCommand('SELECT scene_id FROM setup WHERE device_id = 1')->queryScalar());
    }

    public function testAuthenticatedPutReturnsUpdatedDevice(): void
    {
        $this->auth->login('test-admin', 'test-only-password');
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->headers->set('X-CSRF-Token', $this->app->request->getCsrfToken());
        $this->app->request->setBodyParams(['gameId' => 700]);
        $result = $this->controller()->runAction('set-game', ['id' => 1]);
        $this->assertSame(1, $result['id']);
        $this->assertSame(700, $result['gameId']);
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
            ['GET', 'admin-api/devices', 'admin/devices', []],
            ['GET', 'admin-api/games', 'admin/games', []],
            ['PUT', 'admin-api/devices/42/game', 'admin/set-game', ['id' => '42']],
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
        foreach (['admin-api', 'admin-api/session', 'admin-api/login', 'admin-api/logout', 'admin-api/devices', 'admin-api/games', 'admin-api/devices/1/game'] as $path) {
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
        foreach ([['POST', 'login', 400], ['GET', 'devices', 401]] as $case) {
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

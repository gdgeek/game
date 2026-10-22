<?php

namespace tests\unit\components;

use app\components\Helper;
use app\modules\v2\controllers\ServerController;
use app\modules\v2\models\Applet;
use app\modules\v2\models\RecodeFile;
use app\modules\v2\models\Report;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\caching\ArrayCache;
use yii\web\Application;
use yii\web\HttpException;

/** Signed Unity requests against SQLite and a loopback-only test Redis. */
class UnityDeviceVersionTest extends TestCase
{
    private ?Application $app = null;
    private $originalApp;
    private $originalAliases;
    private $originalContainer;
    private $originalServer;
    private $originalPost;
    private $originalGet;
    private array $tokens = [];

    protected function setUp(): void
    {
        $configuredPort = getenv('UNITY_DEVICE_VERSION_TEST_REDIS_PORT');
        $port = $configuredPort === false || $configuredPort === '' ? 6379 : (int) $configuredPort;
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.25);
        if ($socket === false) {
            if ($configuredPort !== false && $configuredPort !== '') {
                $this->fail('The explicitly configured loopback test Redis is unavailable.');
            }
            $this->markTestSkipped('Start local Redis or set UNITY_DEVICE_VERSION_TEST_REDIS_PORT.');
        }
        fclose($socket);

        $this->originalApp = Yii::$app;
        $this->originalAliases = Yii::$aliases;
        $this->originalContainer = Yii::$container;
        $this->originalServer = $_SERVER;
        $this->originalPost = $_POST;
        $this->originalGet = $_GET;
        Yii::$container = new \yii\di\Container();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/v2/server/device';
        $_POST = $_GET = [];
        $this->app = new Application([
            'id' => 'unity-device-version-test',
            'basePath' => dirname(__DIR__, 3),
            'components' => [
                'request' => [
                    'cookieValidationKey' => 'unity-version-test-only',
                    'parsers' => ['application/json' => 'yii\web\JsonParser'],
                    'scriptFile' => dirname(__DIR__, 3) . '/web/index.php',
                    'scriptUrl' => '/index.php',
                ],
                'db' => ['class' => 'yii\db\Connection', 'dsn' => 'sqlite::memory:'],
                'user' => ['identityClass' => \app\modules\v2\models\User::class, 'enableSession' => false, 'enableAutoLogin' => false],
                'redis' => [
                    'class' => 'yii\redis\Connection',
                    'hostname' => '127.0.0.1',
                    'port' => $port,
                    'database' => 15,
                    'connectionTimeout' => 0.5,
                    'dataTimeout' => 0.5,
                ],
                'cache' => ['class' => ArrayCache::class],
                'helper' => ['class' => Helper::class],
            ],
        ]);
        $this->app->redis->executeCommand('PING');
        $db = $this->app->db;
        $db->open();
        $db->pdo->sqliteCreateFunction('NOW', static function () { return '2026-10-23 12:00:00'; });
        $db->createCommand('CREATE TABLE device (id INTEGER PRIMARY KEY, uuid TEXT UNIQUE, tag TEXT, ip TEXT, created_at TEXT, updated_at TEXT, version INTEGER NOT NULL DEFAULT 0)')->execute();
    }

    protected function tearDown(): void
    {
        if ($this->app === null) {
            return;
        }
        // Delete only this test's random tokens; never flush Redis or other reports.
        foreach ($this->tokens as $token) {
            Report::deleteAll(['token' => $token]);
        }
        $this->app->redis->close();
        $this->app->db->close();
        $this->app->errorHandler->unregister();
        Yii::$app = $this->originalApp;
        Yii::$aliases = $this->originalAliases;
        Yii::$container = $this->originalContainer;
        $_SERVER = $this->originalServer;
        $_POST = $this->originalPost;
        $_GET = $this->originalGet;
    }

    public function testNewDeviceUsesExplicitVersionFromSignedDeviceRequest(): void
    {
        $token = $this->token();
        $result = $this->request('device', ['token' => $token, 'uuid' => 'new-explicit', 'version' => 2]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $this->version('new-explicit'));
        $this->assertSame('new-explicit', Report::findOne($token)->uuid);
    }

    public function testNewDeviceDefaultsToZeroFromSignedRefreshRequest(): void
    {
        $result = $this->request('refresh', ['token' => $this->token(), 'uuid' => 'new-default']);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->version('new-default'));
    }

    public function testExistingDeviceAndAlreadyBoundTokenUseEachReportedVersion(): void
    {
        $token = $this->token();
        $this->seedDevice('existing', 7, $token);
        $this->request('device', ['token' => $token, 'uuid' => 'existing', 'version' => 2]);
        $this->assertSame(2, $this->version('existing'));

        $report = Report::findOne($token);
        $this->assertNotNull($report->device_id);
        $result = $this->request('refresh', ['token' => $token, 'uuid' => 'existing', 'version' => 4]);

        $this->assertTrue($result['success']);
        $this->assertSame(4, $this->version('existing'));
        $this->assertSame($report->device_id, Report::findOne($token)->device_id);
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM device')->queryScalar());
    }

    public function testExplicitZeroAndFormIntegerStringsAreAccepted(): void
    {
        $token = $this->token();
        $this->seedDevice('zero-and-form', 8, $token);
        $this->request('device', ['token' => $token, 'uuid' => 'zero-and-form', 'version' => 0]);
        $this->assertSame(0, $this->version('zero-and-form'));

        $this->request('refresh', ['token' => $token, 'uuid' => 'zero-and-form', 'version' => '6'], [], true);
        $this->assertSame(6, $this->version('zero-and-form'));
        $this->request('device', ['token' => $token, 'uuid' => 'zero-and-form', 'version' => '0'], [], true);
        $this->assertSame(0, $this->version('zero-and-form'));
        $this->request('refresh', ['token' => $token, 'uuid' => 'zero-and-form', 'version' => '2147483647'], [], true);
        $this->assertSame(2147483647, $this->version('zero-and-form'));
    }

    public function testOmittedBodyVersionResetsExistingDeviceAndIgnoresQueryVersion(): void
    {
        $token = $this->token();
        $this->seedDevice('reset-default', 9, $token);
        $this->request('refresh', ['token' => $token, 'uuid' => 'reset-default'], ['version' => 5]);

        $this->assertSame(0, $this->version('reset-default'));
    }

    public function testReadWithExistingReportAndNoUuidPreservesDeviceVersion(): void
    {
        $token = $this->token();
        $deviceId = $this->seedDevice('read-existing-report', 9, $token);
        $result = $this->request('refresh', ['token' => $token, 'key' => 'unity-test-read-only-key']);

        $this->assertTrue($result['success']);
        $this->assertSame($deviceId, (int) $result['data']['device']['device_id']);
        $this->assertSame(9, $this->version('read-existing-report'));
    }

    public function testInvalidVersionCreatesNeitherDeviceNorRedisRecords(): void
    {
        foreach ([true, false, null, [], -1, 1.5, '1.5', 2147483648, '2147483648'] as $index => $version) {
            $token = $this->token();
            $status = null;
            try {
                $this->request('device', ['token' => $token, 'uuid' => 'invalid-version-' . $index, 'version' => $version]);
            } catch (HttpException $exception) {
                $status = $exception->statusCode;
            }

            $this->assertSame(0, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM device')->queryScalar());
            $this->assertNull(Report::findOne($token));
            $this->assertNull(Applet::findOne($token));
            $this->assertNull(RecodeFile::findOne($token));
            $this->assertSame(400, $status, 'Invalid version must be rejected before any persistence.');
        }
    }

    public function testInvalidSignatureStillRejectsRequestBeforePersistence(): void
    {
        $token = $this->token();
        $status = null;
        try {
            $this->request('device', ['token' => $token, 'uuid' => 'bad-signature', 'version' => 2], ['hash' => str_repeat('0', 32)]);
        } catch (HttpException $exception) {
            $status = $exception->statusCode;
        }

        $this->assertSame(400, $status);
        $this->assertSame(0, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM device')->queryScalar());
        $this->assertNull(Report::findOne($token));
    }

    private function request(string $action, array $body, array $queryOverrides = [], bool $form = false): array
    {
        $time = '1792713600';
        $signedValue = $body['uuid'] ?? $body['id'] ?? $body['key'];
        $query = array_merge([
            'time' => $time,
            // Keep the existing client signature: version is not added to its input.
            'hash' => md5($body['token'] . $time . $signedValue . 'buj1aban.c0m'),
            'expand' => 'device',
        ], $queryOverrides);
        $request = $this->app->request;
        $request->setQueryParams($query);
        $request->headers->set('Content-Type', $form ? 'application/x-www-form-urlencoded' : 'application/json');
        $request->setBodyParams(null);
        if ($form) {
            $_POST = $body;
            $request->setRawBody(http_build_query($body));
        } else {
            $_POST = [];
            $request->setRawBody(json_encode($body, JSON_THROW_ON_ERROR));
        }
        $controller = new ServerController('server', $this->app);
        $this->app->controller = $controller;
        return $controller->runAction($action);
    }

    private function seedDevice(string $uuid, int $version, ?string $token = null): int
    {
        $this->app->db->createCommand()->insert('device', ['uuid' => $uuid, 'version' => $version])->execute();
        $id = (int) $this->app->db->getLastInsertID();
        if ($token !== null) {
            $report = new Report();
            $report->token = $token;
            $report->uuid = $uuid;
            $report->device_id = $id;
            $this->assertTrue($report->save());
        }
        return $id;
    }

    private function version(string $uuid): int
    {
        return (int) $this->app->db->createCommand('SELECT version FROM device WHERE uuid = :uuid', [':uuid' => $uuid])->queryScalar();
    }

    private function token(): string
    {
        $token = 'U' . bin2hex(random_bytes(16));
        $this->tokens[] = $token;
        return $token;
    }
}

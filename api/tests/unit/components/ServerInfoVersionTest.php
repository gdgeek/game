<?php

namespace tests\unit\components;

use app\components\AdminGameCatalog;
use app\modules\v2\controllers\ServerController;
use app\modules\v2\helper\Server;
use app\modules\v2\models\Setup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\caching\ArrayCache;
use yii\web\Application;
use yii\web\HttpException;

/** Exercise the public info action with isolated SQLite storage and no Redis. */
class ServerInfoVersionTest extends TestCase
{
    private ?Application $app = null;
    private AdminGameCatalog $catalog;
    private $originalApp;
    private $originalAliases;
    private $originalContainer;
    private $originalServer;
    private $originalGet;
    private $originalPost;

    protected function setUp(): void
    {
        $this->originalApp = Yii::$app;
        $this->originalAliases = Yii::$aliases;
        $this->originalContainer = Yii::$container;
        $this->originalServer = $_SERVER;
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        Yii::$container = new \yii\di\Container();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/v2/server/info';
        $_GET = $_POST = [];
        $this->app = new Application([
            'id' => 'server-info-version-test',
            'basePath' => dirname(__DIR__, 3),
            'components' => [
                'request' => [
                    'cookieValidationKey' => 'server-info-version-test-only',
                    'enableCsrfCookie' => false,
                    'scriptFile' => dirname(__DIR__, 3) . '/web/index.php',
                    'scriptUrl' => '/index.php',
                ],
                'db' => ['class' => 'yii\db\Connection', 'dsn' => 'sqlite::memory:'],
                'user' => ['identityClass' => \app\modules\v2\models\User::class, 'enableSession' => false, 'enableAutoLogin' => false],
                'cache' => ['class' => ArrayCache::class],
            ],
        ]);
        Yii::setAlias('@app', dirname(__DIR__, 3));
        $db = $this->app->db;
        $db->open();
        // Use the real MySQL JSON codecs for SQLite TEXT-backed JSON columns.
        $db->schema->typeMap['json'] = 'json';
        $db->schema->columnSchemaClass = 'yii\\db\\mysql\\ColumnSchema';
        $db->queryBuilder->setExpressionBuilders(['yii\\db\\JsonExpression' => 'yii\\db\\mysql\\JsonExpressionBuilder']);
        $db->pdo->sqliteCreateFunction('NOW', static function () { return '2026-10-23 12:00:00'; });
        $db->createCommand('CREATE TABLE device (id INTEGER PRIMARY KEY, uuid TEXT UNIQUE, tag TEXT, ip TEXT, created_at TEXT, updated_at TEXT, version INTEGER NOT NULL DEFAULT 0)')->execute();
        $db->createCommand('CREATE TABLE setup (id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER, scene_id INTEGER, title TEXT, money REAL DEFAULT 0, slogans JSON, pictures JSON, thumbs JSON, shots JSON, updated_at TEXT)')->execute();
        $db->createCommand('CREATE TABLE update_audit (table_name TEXT, row_id INTEGER)')->execute();
        // Yii's SQLite command splits statements at semicolons; create triggers
        // through PDO so their BEGIN/END bodies stay together.
        $db->pdo->exec("CREATE TRIGGER audit_device_update AFTER UPDATE ON device BEGIN INSERT INTO update_audit VALUES ('device', NEW.id); END");
        $db->pdo->exec("CREATE TRIGGER audit_setup_update AFTER UPDATE ON setup BEGIN INSERT INTO update_audit VALUES ('setup', NEW.id); END");
        $this->catalog = new AdminGameCatalog([
            'cache' => new ArrayCache(),
            'loader' => static function () {
                return ['data' => [['verse_id' => '638', 'name' => '测试场景']]];
            },
        ]);
        Yii::$container->setSingleton(AdminGameCatalog::class, $this->catalog);
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->db->close();
            $this->app->errorHandler->unregister();
        }
        Yii::$app = $this->originalApp;
        Yii::$aliases = $this->originalAliases;
        Yii::$container = $this->originalContainer;
        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
    }

    public function testNewDeviceUsesExplicitQueryVersionAndDefaultConfiguration(): void
    {
        $result = $this->request('new-explicit', ['version' => '2']);

        $this->assertSame(2, $this->version('new-explicit'));
        $this->assertSame(['success', 'message', 'data'], array_keys($result));
        $this->assertTrue($result['success']);
        $this->assertSame('success', $result['message']);
        $this->assertSame(['title', 'pictures', 'scene_id', 'updated_at'], array_keys($result['data']));
        $this->assertSame('未知', $result['data']['title']);
        $this->assertSame(638, $result['data']['scene_id']);
        $this->assertSame(Setup::DefaultInfo()['pictures'], $result['data']['pictures']);
        $this->assertEquals(Setup::DefaultData(), Setup::find()->one()->getData());
        $this->assertSame(1, $this->rowCount('device'));
        $this->assertSame(1, $this->rowCount('setup'));
    }

    public function testNewDeviceDefaultsToZeroWhenQueryVersionIsOmitted(): void
    {
        $result = $this->request('new-default');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->version('new-default'));
        $this->assertSame(1, $this->rowCount('setup'));
    }

    /** @dataProvider changedVersionProvider */
    #[DataProvider('changedVersionProvider')]
    public function testExistingDeviceUsesEachReportedVersion(int $initialVersion, string $reportedVersion, int $expectedVersion): void
    {
        $this->seedDevice('existing', $initialVersion);

        $result = $this->request('existing', ['version' => $reportedVersion]);

        $this->assertTrue($result['success']);
        $this->assertSame($expectedVersion, $this->version('existing'));
        $this->assertSame(1, $this->rowCount('device'));
        $this->assertSame(1, $this->rowCount('setup'));
    }

    public static function changedVersionProvider(): array
    {
        return [
            'upgrade' => [1, '2', 2],
            'downgrade' => [8, '2', 2],
            'explicit zero' => [8, '0', 0],
            'maximum query integer string' => [2, '2147483647', 2147483647],
        ];
    }

    public function testOmittedVersionResetsExistingDeviceToZero(): void
    {
        $this->seedDevice('existing-default', 9);

        $this->request('existing-default');

        $this->assertSame(0, $this->version('existing-default'));
    }

    /** @dataProvider unchangedVersionProvider */
    #[DataProvider('unchangedVersionProvider')]
    public function testUnchangedVersionDoesNotIssueAnyDeviceOrSetupUpdate(int $version, array $query): void
    {
        $this->seedDevice('same-version', $version);
        $before = $this->snapshot();

        $result = $this->request('same-version', $query);

        $this->assertTrue($result['success']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, $this->rowCount('update_audit'), 'Even an UPDATE that writes the same value must be avoided.');
    }

    public static function unchangedVersionProvider(): array
    {
        return [
            'explicit same version' => [2, ['version' => '2']],
            'implicit same zero' => [0, []],
        ];
    }

    public function testVersionChangePreservesExistingConfigurationAndResponseStructure(): void
    {
        $this->seedDevice('configured', 7);
        $before = $this->app->db->createCommand('SELECT * FROM setup')->queryAll();
        $this->catalog->loader = static function () { throw new \RuntimeException('Existing configuration must not fetch defaults.'); };

        $result = $this->request('configured', ['version' => '2']);

        $this->assertSame([
            'success' => true,
            'message' => 'success',
            'data' => [
                'title' => '保留标题',
                'pictures' => ['keep.png'],
                'scene_id' => 700,
                'updated_at' => '2026-10-22 11:00:00',
            ],
        ], $result);
        $this->assertSame(2, $this->version('configured'));
        $this->assertSame($before, $this->app->db->createCommand('SELECT * FROM setup')->queryAll());
        $this->assertSame(0, (int) $this->app->db->createCommand("SELECT COUNT(*) FROM update_audit WHERE table_name = 'setup'")->queryScalar());
    }

    /** @dataProvider invalidVersionProvider */
    #[DataProvider('invalidVersionProvider')]
    public function testInvalidQueryVersionIsRejectedBeforeAnyPersistence($version): void
    {
        $this->seedDevice('existing-invalid', 9);
        $before = $this->snapshot();
        foreach (['new-invalid', 'existing-invalid'] as $uuid) {
            $status = null;
            try {
                $this->request($uuid, ['version' => $version]);
            } catch (HttpException $exception) {
                $status = $exception->statusCode;
            }

            $this->assertSame(400, $status, 'Invalid query version must return HTTP 400.');
            $this->assertSame($before, $this->snapshot(), 'Invalid version must not create or modify records.');
            $this->assertSame(0, $this->rowCount('update_audit'));
        }
    }

    public static function invalidVersionProvider(): array
    {
        return [
            'negative integer' => [-1],
            'negative string' => ['-1'],
            'fractional number' => [1.5],
            'fractional string' => ['1.5'],
            'non-numeric string' => ['invalid'],
            'empty string' => [''],
            'empty array' => [[]],
            'query array' => [['2']],
            'overflow integer' => [2147483648],
            'overflow string' => ['2147483648'],
            'true' => [true],
            'false' => [false],
        ];
    }

    public function testFailedDefaultCatalogLeavesNewDeviceUnpersisted(): void
    {
        $this->catalog->loader = static function () { throw new \RuntimeException('Upstream unavailable.'); };
        $status = null;
        try {
            $this->request('upstream-failure', ['version' => '2']);
        } catch (HttpException $exception) {
            $status = $exception->statusCode;
        }

        $this->assertSame(503, $status);
        $this->assertSame(0, $this->rowCount('device'));
        $this->assertSame(0, $this->rowCount('setup'));
        $this->assertSame(0, $this->rowCount('update_audit'));
    }

    public function testInternalGetInfoWithoutVersionPreservesExistingVersion(): void
    {
        $this->seedDevice('internal-read', 9);
        $before = $this->snapshot();
        // Refresh expand=info calls this helper without reporting a new version.
        $this->app->request->setQueryParams(['version' => '0']);

        $info = Server::getInfo('internal-read');

        $this->assertSame('保留标题', $info['title']);
        $this->assertSame(9, $this->version('internal-read'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, $this->rowCount('update_audit'));
    }

    private function request(string $uuid, array $parameters = []): array
    {
        $query = array_merge(['uuid' => $uuid], $parameters);
        $_GET = $query;
        $this->app->request->setQueryParams($query);
        $controller = new ServerController('server', $this->app);
        $this->app->controller = $controller;
        // The framework passes query parameters to the action during routing.
        return $controller->runAction('info', $query);
    }

    private function seedDevice(string $uuid, int $version): void
    {
        $this->app->db->createCommand()->insert('device', [
            'uuid' => $uuid,
            'version' => $version,
            'tag' => '保留标签',
            'ip' => '127.0.0.2',
            'created_at' => '2026-10-21 10:00:00',
            'updated_at' => '2026-10-22 10:00:00',
        ])->execute();
        $deviceId = (int) $this->app->db->getLastInsertID();
        $this->app->db->createCommand()->insert('setup', [
            'device_id' => $deviceId,
            'scene_id' => 700,
            'title' => '保留标题',
            'money' => 9.9,
            'slogans' => ['保留口号'],
            'pictures' => ['keep.png'],
            'thumbs' => ['keep.webp'],
            'shots' => [1, 5],
            'updated_at' => '2026-10-22 11:00:00',
        ])->execute();
    }

    private function version(string $uuid): int
    {
        return (int) $this->app->db->createCommand('SELECT version FROM device WHERE uuid = :uuid', [':uuid' => $uuid])->queryScalar();
    }

    private function rowCount(string $table): int
    {
        return (int) $this->app->db->createCommand('SELECT COUNT(*) FROM ' . $table)->queryScalar();
    }

    private function snapshot(): array
    {
        return [
            'device' => $this->app->db->createCommand('SELECT * FROM device ORDER BY id')->queryAll(),
            'setup' => $this->app->db->createCommand('SELECT * FROM setup ORDER BY id')->queryAll(),
        ];
    }
}

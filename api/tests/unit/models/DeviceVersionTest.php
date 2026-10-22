<?php

namespace tests\unit\models;

use app\modules\v1\models\Device as V1Device;
use app\modules\v2\models\Device as V2Device;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;

class DeviceVersionTest extends TestCase
{
    private const DEVICE_MODELS = [V1Device::class, V2Device::class];

    private $app;
    private $originalApp;
    private $originalAliases;

    protected function setUp(): void
    {
        $this->originalApp = Yii::$app;
        $this->originalAliases = Yii::$aliases;
        $this->app = new Application([
            'id' => 'device-version-test',
            'basePath' => dirname(__DIR__, 3),
            'components' => [
                'db' => ['class' => 'yii\db\Connection', 'dsn' => 'sqlite::memory:'],
            ],
        ]);
        $db = $this->app->db;
        $db->open();
        $db->pdo->sqliteCreateFunction('NOW', static function () { return '2026-10-23 12:00:00'; });
        // shop_id supports the legacy v1 model's validation rules.
        $db->createCommand('CREATE TABLE device (id INTEGER PRIMARY KEY, shop_id INTEGER, uuid TEXT, tag TEXT, ip TEXT, created_at TEXT, updated_at TEXT)')->execute();
        $db->createCommand()->insert('device', ['id' => 1, 'uuid' => 'existing-device'])->execute();
    }

    protected function tearDown(): void
    {
        $this->app->db->close();
        $this->app->errorHandler->unregister();
        Yii::$app = $this->originalApp;
        Yii::$aliases = $this->originalAliases;
    }

    public function testMigrationKeepsExistingDevicesAndDatabaseDefaultAtZero(): void
    {
        $this->migrate()->safeUp();

        $db = $this->app->db;
        $column = $db->schema->getTableSchema('device', true)->columns['version'];
        $this->assertSame('integer', $column->type);
        $this->assertFalse($column->allowNull);
        $this->assertSame(0, (int) $column->defaultValue);
        $this->assertSame(0, (int) $db->createCommand('SELECT version FROM device WHERE id = 1')->queryScalar());

        $db->createCommand()->insert('device', ['uuid' => 'database-default'])->execute();
        $this->assertSame(0, (int) $db->createCommand('SELECT version FROM device WHERE uuid = :uuid', [':uuid' => 'database-default'])->queryScalar());
    }

    public function testNewDeviceIsSavedWithVersionOne(): void
    {
        $this->migrate()->safeUp();
        foreach (self::DEVICE_MODELS as $index => $modelClass) {
            $device = new $modelClass();
            $device->uuid = 'new-device-' . $index;

            $this->assertTrue($device->save());
            $this->assertTrue($device->refresh());
            $this->assertSame(1, (int) $device->version, $modelClass);
        }
    }

    public function testNewDeviceUsesVersionOneEvenWithDatabaseDefaultsAndWithoutValidation(): void
    {
        $this->migrate()->safeUp();
        foreach (self::DEVICE_MODELS as $index => $modelClass) {
            $device = new $modelClass();
            $device->loadDefaultValues();
            $device->uuid = 'new-device-with-defaults-' . $index;
            $this->assertSame(0, (int) $device->version);

            $this->assertTrue($device->save(false));
            $this->assertTrue($device->refresh());
            $this->assertSame(1, (int) $device->version, $modelClass);
        }
    }

    public function testUpdatingDevicePreservesItsStoredVersion(): void
    {
        $this->migrate()->safeUp();
        foreach (self::DEVICE_MODELS as $modelClass) {
            foreach ([0, 1, 7] as $version) {
                $this->app->db->createCommand()->update('device', ['version' => $version], ['id' => 1])->execute();
                $device = $modelClass::findOne(1);
                $device->ip = '192.0.2.' . ($version + 1);

                $this->assertTrue($device->save());
                $this->assertTrue($device->refresh());
                $this->assertSame($version, (int) $device->version, $modelClass);
            }
        }
    }

    private function migrate()
    {
        $path = dirname(__DIR__, 3) . '/migrations/m261023_120000_add_version_column_to_device_table.php';
        $this->assertFileExists($path, 'The device version migration must be available.');
        require_once $path;
        return new \m261023_120000_add_version_column_to_device_table(['db' => $this->app->db, 'compact' => true]);
    }
}

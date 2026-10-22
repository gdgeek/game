<?php

namespace tests\unit\components;

use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\db\Connection;

/** Runs the actual schema migration without deployment-only configuration. */
class DeviceRecordMigrationTest extends TestCase
{
    private $app;
    private $db;
    private $originalApp;
    private $originalAliases;
    private $originalContainer;

    protected function setUp(): void
    {
        $this->originalApp = Yii::$app;
        $this->originalAliases = Yii::$aliases;
        $this->originalContainer = Yii::$container;
        Yii::$container = new \yii\di\Container();
        $this->app = new Application([
            'id' => 'device-record-migration-test',
            'basePath' => dirname(__DIR__, 3),
            'components' => ['db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:']],
        ]);
        $this->db = $this->app->db;
        $this->db->open();
        // Codeception starts a transaction on every new connection. This
        // disposable database needs foreign keys enabled before any transaction.
        $transaction = $this->db->getTransaction();
        if ($transaction !== null) {
            $transaction->rollBack();
        }
        $this->db->createCommand('PRAGMA foreign_keys = ON')->execute();
        $this->assertSame(1, (int) $this->db->createCommand('PRAGMA foreign_keys')->queryScalar());
        $this->db->createCommand('CREATE TABLE device (id INTEGER PRIMARY KEY)')->execute();
        $this->db->createCommand('CREATE TABLE player (id INTEGER PRIMARY KEY)')->execute();
        $this->db->createCommand('CREATE TABLE record (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id INTEGER NOT NULL,
            player_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT,
            status TEXT NOT NULL DEFAULT \'ready\',
            game JSON,
            startTime INTEGER,
            endTime INTEGER,
            CONSTRAINT "fk-record-device_id" FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT "fk-record-player_id" FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE
        )')->execute();
        $this->db->createCommand('CREATE INDEX "idx-record-device_id" ON record (device_id)')->execute();
        $this->db->createCommand('CREATE INDEX "idx-record-player_id" ON record (player_id)')->execute();
        $this->db->createCommand('CREATE UNIQUE INDEX "idx-record-player-start" ON record (player_id, startTime) WHERE startTime IS NOT NULL')->execute();
        $this->db->createCommand('CREATE TABLE record_audit (record_id INTEGER)')->execute();
        $this->db->pdo->exec('CREATE TRIGGER "tr-record-audit" AFTER INSERT ON record BEGIN INSERT INTO record_audit VALUES (NEW.id); END');
        $this->db->createCommand('INSERT INTO device VALUES (1), (2)')->execute();
        $this->db->createCommand('INSERT INTO player VALUES (3), (4)')->execute();
        $this->db->createCommand()->insert('record', [
            'id' => 7, 'device_id' => 1, 'player_id' => 3,
            'created_at' => '2026-10-23 13:00:00', 'updated_at' => '2026-10-23 13:01:00',
            'status' => 'finish', 'game' => '{"title":"保留历史"}', 'startTime' => 15, 'endTime' => 25,
        ])->execute();
        $this->db->createCommand()->insert('record', [
            'id' => 50, 'device_id' => 2, 'player_id' => 4, 'created_at' => '2026-10-23 13:00:00',
        ])->execute();
        $this->db->createCommand('DELETE FROM record WHERE id = 50')->execute();
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $this->app->errorHandler->unregister();
        Yii::$app = $this->originalApp;
        Yii::$aliases = $this->originalAliases;
        Yii::$container = $this->originalContainer;
    }

    public function testDeletingDevicePreservesEveryHistoricalValueAndDetachesOnlyDevice(): void
    {
        $before = $this->db->createCommand('SELECT * FROM record WHERE id = 7')->queryOne();
        $this->migrate();
        $this->db->createCommand('DELETE FROM device WHERE id = 1')->execute();

        $before['device_id'] = null;
        $this->assertSame($before, $this->db->createCommand('SELECT * FROM record WHERE id = 7')->queryOne());
        $this->assertSame([], $this->db->createCommand('PRAGMA foreign_key_check')->queryAll());
        $this->assertSame(1, (int) $this->db->createCommand('PRAGMA foreign_keys')->queryScalar());
    }

    public function testRerunRetainsIndexesTriggersSequenceAndOtherForeignKeyActions(): void
    {
        $objects = $this->db->createCommand("SELECT name, sql FROM sqlite_master WHERE tbl_name = 'record' AND type IN ('index', 'trigger') ORDER BY name")->queryAll();
        $this->migrate();
        $this->migrate();
        $this->assertSame($objects, $this->db->createCommand("SELECT name, sql FROM sqlite_master WHERE tbl_name = 'record' AND type IN ('index', 'trigger') ORDER BY name")->queryAll());
        $this->db->createCommand('UPDATE device SET id = 8 WHERE id = 1')->execute();
        $this->assertSame(8, (int) $this->db->createCommand('SELECT device_id FROM record WHERE id = 7')->queryScalar());
        $this->db->createCommand()->insert('record', ['device_id' => null, 'player_id' => 4, 'created_at' => '2026-10-23 14:00:00'])->execute();
        $newId = (int) $this->db->lastInsertID;
        $this->assertGreaterThan(50, $newId);
        $this->assertSame(1, (int) $this->db->createCommand('SELECT COUNT(*) FROM record_audit WHERE record_id = :id', [':id' => $newId])->queryScalar());
        $this->db->createCommand('DELETE FROM player WHERE id = 3')->execute();
        $this->assertSame(0, (int) $this->db->createCommand('SELECT COUNT(*) FROM record WHERE id = 7')->queryScalar());
    }

    public function testUnsafeRollbackDoesNotDiscardDetachedRecords(): void
    {
        $migration = $this->migrate();
        $this->db->createCommand('DELETE FROM device WHERE id = 1')->execute();
        ob_start();
        try {
            $this->assertFalse($migration->down());
        } finally {
            ob_end_clean();
        }
        $this->assertSame(1, (int) $this->db->createCommand('SELECT COUNT(*) FROM record WHERE id = 7 AND device_id IS NULL')->queryScalar());
    }

    public function testMigrationUsesConfiguredTablePrefix(): void
    {
        foreach (['device', 'player', 'record'] as $table) {
            $this->db->createCommand()->renameTable($table, 'test_' . $table)->execute();
        }
        $this->db->tablePrefix = 'test_';
        $this->migrate();
        $this->db->createCommand('DELETE FROM test_device WHERE id = 1')->execute();
        $this->assertSame(1, (int) $this->db->createCommand('SELECT COUNT(*) FROM test_record WHERE id = 7 AND device_id IS NULL')->queryScalar());
        $this->assertSame([], $this->db->createCommand('PRAGMA foreign_key_check')->queryAll());
    }

    public function testRebuildFailureRollsBackWithoutChangingRecordsOrIndexes(): void
    {
        $this->db->createCommand('CREATE VIEW record_history AS SELECT * FROM record')->execute();
        $before = $this->db->createCommand("SELECT name, sql FROM sqlite_master WHERE tbl_name = 'record' ORDER BY name")->queryAll();
        $migration = $this->newMigration();
        ob_start();
        try {
            // SQLite rejects renaming the replacement while the view references
            // the temporarily missing original; the surrounding DDL rolls back.
            $this->assertFalse($migration->up());
        } finally {
            ob_end_clean();
        }
        $this->assertSame($before, $this->db->createCommand("SELECT name, sql FROM sqlite_master WHERE tbl_name = 'record' ORDER BY name")->queryAll());
        $this->assertSame(1, (int) $this->db->createCommand('SELECT COUNT(*) FROM record_history')->queryScalar());
        $this->assertSame([], $this->db->createCommand('PRAGMA foreign_key_check')->queryAll());
    }

    public function testUnsupportedIncomingSqliteForeignKeyFailsWithoutChangingRows(): void
    {
        $this->db->createCommand('CREATE TABLE record_detail (record_id INTEGER REFERENCES record (id) ON DELETE CASCADE)')->execute();
        $this->db->createCommand('INSERT INTO record_detail VALUES (7)')->execute();
        $before = $this->db->createCommand("SELECT sql FROM sqlite_master WHERE name = 'record'")->queryScalar();
        $migration = $this->newMigration();
        ob_start();
        try {
            $this->assertFalse($migration->up());
        } finally {
            ob_end_clean();
        }
        $this->assertSame($before, $this->db->createCommand("SELECT sql FROM sqlite_master WHERE name = 'record'")->queryScalar());
        $this->assertSame(1, (int) $this->db->createCommand('SELECT COUNT(*) FROM record_detail')->queryScalar());
        $this->assertSame(1, (int) $this->db->createCommand('SELECT COUNT(*) FROM record')->queryScalar());
    }

    public function testMysqlAlterUsesDistinctDeterministicConstraintNameWithinIdentifierLimit(): void
    {
        $path = dirname(__DIR__, 3) . '/migrations/m261023_130000_preserve_records_on_device_deletion.php';
        $this->assertFileExists($path);
        require_once $path;
        $db = new RecordMigrationSqlCaptureConnection(['dsn' => 'mysql:dbname=unused', 'tablePrefix' => 'test_']);
        $migration = new \m261023_130000_preserve_records_on_device_deletion(['db' => $db, 'compact' => true]);
        ob_start();
        try {
            $migration->up();
            $migration->up();
        } finally {
            ob_end_clean();
        }
        $this->assertCount(2, $db->statements);
        $this->assertSame($db->statements[0], $db->statements[1]);
        $this->assertSame(1, preg_match('/DROP FOREIGN KEY `([^`]+)`.*ADD CONSTRAINT `([^`]+)`/', $db->statements[0], $names));
        $this->assertSame(str_repeat('k', 64), $names[1]);
        $this->assertNotSame($names[1], $names[2], 'MySQL rejects dropping and adding the same FK name in one ALTER.');
        $this->assertLessThanOrEqual(64, strlen($names[2]));
        $this->assertStringContainsString('ALTER TABLE `test_record`', $db->statements[0]);
        $this->assertStringContainsString('ON DELETE SET NULL ON UPDATE CASCADE', $db->statements[0]);
    }

    private function migrate()
    {
        $migration = $this->newMigration();
        ob_start();
        try {
            $this->assertNotFalse($migration->up());
        } finally {
            ob_end_clean();
        }
        return $migration;
    }

    private function newMigration()
    {
        $path = dirname(__DIR__, 3) . '/migrations/m261023_130000_preserve_records_on_device_deletion.php';
        $this->assertFileExists($path, 'Deleting a device requires a history-preserving schema migration.');
        require_once $path;
        return new \m261023_130000_preserve_records_on_device_deletion(['db' => $this->db, 'compact' => true]);
    }
}

/** SQL construction coverage only; the SQLite tests above execute real DDL. */
class RecordMigrationSqlCaptureConnection extends Connection
{
    public $statements = [];

    public function getSchema()
    {
        return new RecordMigrationSqlCaptureSchema(['db' => $this]);
    }

    public function createCommand($sql = null, $params = [])
    {
        return new RecordMigrationSqlCaptureCommand(['db' => $this, 'sql' => $sql]);
    }
}

class RecordMigrationSqlCaptureCommand extends \yii\db\Command
{
    public function execute()
    {
        $this->db->statements[] = $this->getSql();
        return 0;
    }
}

class RecordMigrationSqlCaptureSchema extends \yii\db\mysql\Schema
{
    public function getTableSchema($name, $refresh = false)
    {
        return new \yii\db\TableSchema([
            'fullName' => $this->getRawTableName($name),
            'columns' => ['device_id' => new \yii\db\ColumnSchema(['dbType' => 'int', 'allowNull' => false])],
        ]);
    }

    public function getTableForeignKeys($name, $refresh = false)
    {
        return [new \yii\db\ForeignKeyConstraint([
            'name' => str_repeat('k', 64), 'columnNames' => ['device_id'],
            'foreignTableName' => $this->getRawTableName('{{%device}}'), 'foreignColumnNames' => ['id'],
            'onDelete' => 'CASCADE', 'onUpdate' => 'CASCADE',
        ])];
    }

    public function refreshTableSchema($name)
    {
    }
}

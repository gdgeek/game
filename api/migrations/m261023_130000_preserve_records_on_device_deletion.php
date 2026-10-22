<?php

use yii\db\Migration;

/** Device removal must detach historical records instead of cascading into them. */
class m261023_130000_preserve_records_on_device_deletion extends Migration
{
    public function up()
    {
        // MySQL DDL commits implicitly. Keep the column/FK change in one statement.
        if ($this->db->driverName === 'mysql') {
            return $this->migrateMysql();
        }
        return parent::up();
    }

    public function safeUp()
    {
        if ($this->db->driverName !== 'sqlite') {
            throw new \RuntimeException('Record preservation supports MySQL and SQLite only.');
        }
        $this->migrateSqlite();
    }

    public function down()
    {
        echo "This migration cannot be reverted: detached historical records must remain preserved.\n";
        return false;
    }

    private function migrateMysql()
    {
        $schema = $this->db->schema;
        $table = $schema->getTableSchema('{{%record}}', true);
        if ($table === null || !isset($table->columns['device_id'])) {
            throw new \RuntimeException('The record.device_id column is required.');
        }
        $foreignKeys = array_values(array_filter($schema->getTableForeignKeys('{{%record}}', true), static function ($key) {
            return $key->columnNames === ['device_id'];
        }));
        if (count($foreignKeys) !== 1 || $foreignKeys[0]->foreignTableName !== $schema->getRawTableName('{{%device}}') || $foreignKeys[0]->foreignColumnNames !== ['id']) {
            throw new \RuntimeException('Expected one record.device_id foreign key to device.id.');
        }
        $key = $foreignKeys[0];
        if ($table->columns['device_id']->allowNull && strtoupper($key->onDelete) === 'SET NULL') {
            return;
        }
        $update = strtoupper($key->onUpdate ?: 'RESTRICT');
        if (!in_array($update, ['CASCADE', 'RESTRICT', 'NO ACTION', 'SET NULL', 'SET DEFAULT'], true)) {
            throw new \RuntimeException('Unsupported record.device_id ON UPDATE action.');
        }
        $quote = [$this->db, 'quoteColumnName'];
        $record = $this->db->quoteTableName($table->fullName);
        $device = $this->db->quoteTableName($schema->getRawTableName('{{%device}}'));
        $name = $quote($key->name);
        // MySQL rejects DROP + ADD of the same FK name within one ALTER.
        $replacementName = $quote('fk_record_device_history_' . substr(hash('sha256', $table->fullName . "\0" . $key->name), 0, 32));
        $column = $quote('device_id');
        $type = $table->columns['device_id']->dbType;
        $this->execute("ALTER TABLE {$record} DROP FOREIGN KEY {$name}, MODIFY COLUMN {$column} {$type} NULL, ADD CONSTRAINT {$replacementName} FOREIGN KEY ({$column}) REFERENCES {$device} (" . $quote('id') . ") ON DELETE SET NULL ON UPDATE {$update}");
        $schema->refreshTableSchema('{{%record}}');
    }

    private function migrateSqlite()
    {
        $schema = $this->db->schema;
        $table = $schema->getRawTableName('{{%record}}');
        $device = $schema->getRawTableName('{{%device}}');
        $quotedTable = $this->db->quoteTableName($table);
        $columns = $this->db->createCommand('PRAGMA table_info(' . $quotedTable . ')')->queryAll();
        $deviceColumns = array_values(array_filter($columns, static function ($column) { return $column['name'] === 'device_id'; }));
        $keys = $this->db->createCommand('PRAGMA foreign_key_list(' . $quotedTable . ')')->queryAll();
        $keys = array_values(array_filter($keys, static function ($key) { return $key['from'] === 'device_id'; }));
        if (count($deviceColumns) !== 1 || count($keys) !== 1 || $keys[0]['table'] !== $device || $keys[0]['to'] !== 'id') {
            throw new \RuntimeException('Expected record.device_id and its foreign key to device.id.');
        }
        if (!(bool) $deviceColumns[0]['notnull'] && strtoupper($keys[0]['on_delete']) === 'SET NULL') {
            return;
        }
        // Rebuilding a referenced SQLite table could cascade into its children.
        // Fail before touching anything rather than disabling integrity checks.
        foreach ($schema->getTableNames('', true) as $name) {
            foreach ($this->db->createCommand('PRAGMA foreign_key_list(' . $this->db->quoteTableName($name) . ')')->queryAll() as $key) {
                if ($key['table'] === $table) {
                    throw new \RuntimeException('Cannot rebuild SQLite record while other tables reference it.');
                }
            }
        }
        $sql = $this->db->createCommand('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = :name', [':name' => $table])->queryScalar();
        $identifier = '(?:"device_id"|`device_id`|\[device_id\]|device_id)';
        if ((bool) $deviceColumns[0]['notnull']) {
            $sql = preg_replace('~([,(]\s*' . $identifier . '\s+(?:INTEGER|INT)\s+)NOT\s+NULL\b~i', '$1', $sql, 1, $count);
            if ($count !== 1) {
                throw new \RuntimeException('Unsupported SQLite device_id column definition.');
            }
        }
        $deviceName = preg_quote($device, '~');
        $deviceIdentifier = '(?:"' . $deviceName . '"|`' . $deviceName . '`|\[' . $deviceName . '\]|' . $deviceName . ')';
        $pattern = '~(FOREIGN\s+KEY\s*\(\s*' . $identifier . '\s*\)\s+REFERENCES\s+' . $deviceIdentifier . '\s*\(\s*(?:"id"|`id`|\[id\]|id)\s*\))((?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:SET\s+(?:NULL|DEFAULT)|NO\s+ACTION|CASCADE|RESTRICT))*)~i';
        $sql = preg_replace_callback($pattern, static function ($match) {
            $actions = preg_replace('~ON\s+DELETE\s+(?:SET\s+(?:NULL|DEFAULT)|NO\s+ACTION|CASCADE|RESTRICT)~i', 'ON DELETE SET NULL', $match[2], 1, $count);
            return $match[1] . $actions . ($count ? '' : ' ON DELETE SET NULL');
        }, $sql, -1, $count);
        if ($count !== 1) {
            throw new \RuntimeException('Unsupported SQLite device_id foreign key definition.');
        }
        $temporary = $table . '__preserve_device_history';
        $quotedTemporary = $this->db->quoteTableName($temporary);
        $sql = preg_replace('~\ACREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)~i', 'CREATE TABLE ' . $quotedTemporary, $sql, 1, $count);
        if ($count !== 1) {
            throw new \RuntimeException('Unsupported SQLite record table definition.');
        }
        $objects = $this->db->createCommand('SELECT sql FROM sqlite_master WHERE tbl_name = :name AND type IN (\'index\', \'trigger\') AND sql IS NOT NULL', [':name' => $table])->queryColumn();
        $sequence = stripos($sql, 'AUTOINCREMENT') === false ? null : $this->db->createCommand('SELECT seq FROM sqlite_sequence WHERE name = :name', [':name' => $table])->queryScalar();
        $columnNames = implode(', ', array_map([$this->db, 'quoteColumnName'], array_column($columns, 'name')));
        $this->execute($sql);
        $this->execute("INSERT INTO {$quotedTemporary} ({$columnNames}) SELECT {$columnNames} FROM {$quotedTable}");
        $this->dropTable($table);
        $this->renameTable($temporary, $table);
        foreach ($objects as $object) {
            // Yii's SQLite command splits statements at semicolons inside triggers.
            $this->db->pdo->exec($object);
        }
        if ($sequence !== null && $sequence !== false) {
            $this->db->createCommand()->update('sqlite_sequence', ['seq' => (int) $sequence], ['name' => $table])->execute();
        }
        if ($this->db->createCommand('PRAGMA foreign_key_check(' . $quotedTable . ')')->queryAll() !== []) {
            throw new \RuntimeException('Foreign key validation failed while rebuilding record.');
        }
        $schema->refreshTableSchema($table);
    }
}

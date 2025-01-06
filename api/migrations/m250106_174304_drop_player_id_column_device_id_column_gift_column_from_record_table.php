<?php

use yii\db\Migration;

/**
 * Handles dropping columns from table `{{%record}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%player}}`
 * - `{{%device}}`
 */
class m250106_174304_drop_player_id_column_device_id_column_gift_column_from_record_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        // drops foreign key for table `{{%player}}`
        $this->dropForeignKey(
            '{{%fk-record-player_id}}',
            '{{%record}}'
        );

        // drops index for column `player_id`
        $this->dropIndex(
            '{{%idx-record-player_id}}',
            '{{%record}}'
        );

        // drops foreign key for table `{{%device}}`
        $this->dropForeignKey(
            '{{%fk-record-device_id}}',
            '{{%record}}'
        );

        // drops index for column `device_id`
        $this->dropIndex(
            '{{%idx-record-device_id}}',
            '{{%record}}'
        );

        $this->dropColumn('{{%record}}', 'player_id');
        $this->dropColumn('{{%record}}', 'device_id');
        $this->dropColumn('{{%record}}', 'gift');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->addColumn('{{%record}}', 'player_id', $this->integer()->notNull());
        $this->addColumn('{{%record}}', 'device_id', $this->integer()->notNull());
        $this->addColumn('{{%record}}', 'gift', $this->json());

        // creates index for column `player_id`
        $this->createIndex(
            '{{%idx-record-player_id}}',
            '{{%record}}',
            'player_id'
        );

        // add foreign key for table `{{%player}}`
        $this->addForeignKey(
            '{{%fk-record-player_id}}',
            '{{%record}}',
            'player_id',
            '{{%player}}',
            'id',
            'CASCADE'
        );

        // creates index for column `device_id`
        $this->createIndex(
            '{{%idx-record-device_id}}',
            '{{%record}}',
            'device_id'
        );

        // add foreign key for table `{{%device}}`
        $this->addForeignKey(
            '{{%fk-record-device_id}}',
            '{{%record}}',
            'device_id',
            '{{%device}}',
            'id',
            'CASCADE'
        );
    }
}

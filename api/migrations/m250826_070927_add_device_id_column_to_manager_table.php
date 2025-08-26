<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%manager}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%device}}`
 */
class m250826_070927_add_device_id_column_to_manager_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%manager}}', 'device_id', $this->integer());

        // creates index for column `device_id`
        $this->createIndex(
            '{{%idx-manager-device_id}}',
            '{{%manager}}',
            'device_id'
        );

        // add foreign key for table `{{%device}}`
        $this->addForeignKey(
            '{{%fk-manager-device_id}}',
            '{{%manager}}',
            'device_id',
            '{{%device}}',
            'id',
            'CASCADE'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        // drops foreign key for table `{{%device}}`
        $this->dropForeignKey(
            '{{%fk-manager-device_id}}',
            '{{%manager}}'
        );

        // drops index for column `device_id`
        $this->dropIndex(
            '{{%idx-manager-device_id}}',
            '{{%manager}}'
        );

        $this->dropColumn('{{%manager}}', 'device_id');
    }
}

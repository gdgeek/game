<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%control}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%user}}`
 * - `{{%device}}`
 */
class m250826_074340_create_control_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {

        
        if ($this->db->driverName === 'mysql') {
            // http://stackoverflow.com/questions/766809/whats-the-difference-between-utf8-general-ci-and-utf8-unicode-ci
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }
        $this->createTable('{{%control}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'device_id' => $this->integer()->notNull(),
        ], $tableOptions);

        // creates index for column `user_id`
        $this->createIndex(
            '{{%idx-control-user_id}}',
            '{{%control}}',
            'user_id'
        );

        // add foreign key for table `{{%user}}`
        $this->addForeignKey(
            '{{%fk-control-user_id}}',
            '{{%control}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE'
        );

        // creates index for column `device_id`
        $this->createIndex(
            '{{%idx-control-device_id}}',
            '{{%control}}',
            'device_id'
        );

        // add foreign key for table `{{%device}}`
        $this->addForeignKey(
            '{{%fk-control-device_id}}',
            '{{%control}}',
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
        // drops foreign key for table `{{%user}}`
        $this->dropForeignKey(
            '{{%fk-control-user_id}}',
            '{{%control}}'
        );

        // drops index for column `user_id`
        $this->dropIndex(
            '{{%idx-control-user_id}}',
            '{{%control}}'
        );

        // drops foreign key for table `{{%device}}`
        $this->dropForeignKey(
            '{{%fk-control-device_id}}',
            '{{%control}}'
        );

        // drops index for column `device_id`
        $this->dropIndex(
            '{{%idx-control-device_id}}',
            '{{%control}}'
        );

        $this->dropTable('{{%control}}');
    }
}

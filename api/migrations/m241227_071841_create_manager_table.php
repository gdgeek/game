<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%manager}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%player}}`
 * - `{{%shop}}`
 */
class m241227_071841_create_manager_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {

        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            // http://stackoverflow.com/questions/766809/whats-the-difference-between-utf8-general-ci-and-utf8-unicode-ci
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }
        $this->createTable('{{%manager}}', [
            'id' => $this->primaryKey(),
            'player_id' => $this->integer()->notNull()->unique(),
            'shop_id' => $this->integer()->notNull(),
        ], $tableOptions);

        // creates index for column `player_id`
        $this->createIndex(
            '{{%idx-manager-player_id}}',
            '{{%manager}}',
            'player_id'
        );

        // add foreign key for table `{{%player}}`
        $this->addForeignKey(
            '{{%fk-manager-player_id}}',
            '{{%manager}}',
            'player_id',
            '{{%player}}',
            'id',
            'CASCADE'
        );

        // creates index for column `shop_id`
        $this->createIndex(
            '{{%idx-manager-shop_id}}',
            '{{%manager}}',
            'shop_id'
        );

        // add foreign key for table `{{%shop}}`
        $this->addForeignKey(
            '{{%fk-manager-shop_id}}',
            '{{%manager}}',
            'shop_id',
            '{{%shop}}',
            'id',
            'CASCADE'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        // drops foreign key for table `{{%player}}`
        $this->dropForeignKey(
            '{{%fk-manager-player_id}}',
            '{{%manager}}'
        );

        // drops index for column `player_id`
        $this->dropIndex(
            '{{%idx-manager-player_id}}',
            '{{%manager}}'
        );

        // drops foreign key for table `{{%shop}}`
        $this->dropForeignKey(
            '{{%fk-manager-shop_id}}',
            '{{%manager}}'
        );

        // drops index for column `shop_id`
        $this->dropIndex(
            '{{%idx-manager-shop_id}}',
            '{{%manager}}'
        );

        $this->dropTable('{{%manager}}');
    }
}

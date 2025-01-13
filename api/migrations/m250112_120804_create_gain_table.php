<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%gain}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%player}}`
 * - `{{%shop}}`
 * - `{{%gift}}`
 */
class m250112_120804_create_gain_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%gain}}', [
            'id' => $this->primaryKey(),
            'player_id' => $this->integer()->notNull(),
            'shop_id' => $this->integer()->notNull(),
            'created_at' => $this->datetime()->notNull(),
            'updated_at' => $this->datetime()->notNull(),
            'type' => $this->string()->notNull(),
            'gift_id' => $this->integer(),
        ]);

        // creates index for column `player_id`
        $this->createIndex(
            '{{%idx-gain-player_id}}',
            '{{%gain}}',
            'player_id'
        );

        // add foreign key for table `{{%player}}`
        $this->addForeignKey(
            '{{%fk-gain-player_id}}',
            '{{%gain}}',
            'player_id',
            '{{%player}}',
            'id',
            'CASCADE'
        );

        // creates index for column `shop_id`
        $this->createIndex(
            '{{%idx-gain-shop_id}}',
            '{{%gain}}',
            'shop_id'
        );

        // add foreign key for table `{{%shop}}`
        $this->addForeignKey(
            '{{%fk-gain-shop_id}}',
            '{{%gain}}',
            'shop_id',
            '{{%shop}}',
            'id',
            'CASCADE'
        );

        // creates index for column `gift_id`
        $this->createIndex(
            '{{%idx-gain-gift_id}}',
            '{{%gain}}',
            'gift_id'
        );

        // add foreign key for table `{{%gift}}`
        $this->addForeignKey(
            '{{%fk-gain-gift_id}}',
            '{{%gain}}',
            'gift_id',
            '{{%gift}}',
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
            '{{%fk-gain-player_id}}',
            '{{%gain}}'
        );

        // drops index for column `player_id`
        $this->dropIndex(
            '{{%idx-gain-player_id}}',
            '{{%gain}}'
        );

        // drops foreign key for table `{{%shop}}`
        $this->dropForeignKey(
            '{{%fk-gain-shop_id}}',
            '{{%gain}}'
        );

        // drops index for column `shop_id`
        $this->dropIndex(
            '{{%idx-gain-shop_id}}',
            '{{%gain}}'
        );

        // drops foreign key for table `{{%gift}}`
        $this->dropForeignKey(
            '{{%fk-gain-gift_id}}',
            '{{%gain}}'
        );

        // drops index for column `gift_id`
        $this->dropIndex(
            '{{%idx-gain-gift_id}}',
            '{{%gain}}'
        );

        $this->dropTable('{{%gain}}');
    }
}

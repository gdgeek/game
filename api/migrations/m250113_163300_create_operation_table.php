<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%operation}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%shop}}`
 */
class m250113_163300_create_operation_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%operation}}', [
            'id' => $this->primaryKey(),
            'shop_id' => $this->integer()->notNull(),
            'pool' => $this->integer()->notNull()->defaultValue(0),
            'income' => $this->integer()->notNull()->defaultValue(0),
            'expense' => $this->integer()->notNull()->defaultValue(0),
        ]);

        // creates index for column `shop_id`
        $this->createIndex(
            '{{%idx-operation-shop_id}}',
            '{{%operation}}',
            'shop_id'
        );

        // add foreign key for table `{{%shop}}`
        $this->addForeignKey(
            '{{%fk-operation-shop_id}}',
            '{{%operation}}',
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
        // drops foreign key for table `{{%shop}}`
        $this->dropForeignKey(
            '{{%fk-operation-shop_id}}',
            '{{%operation}}'
        );

        // drops index for column `shop_id`
        $this->dropIndex(
            '{{%idx-operation-shop_id}}',
            '{{%operation}}'
        );

        $this->dropTable('{{%operation}}');
    }
}

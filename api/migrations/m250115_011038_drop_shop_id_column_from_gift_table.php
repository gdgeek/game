<?php

use yii\db\Migration;

/**
 * Handles dropping columns from table `{{%gift}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%shop}}`
 */
class m250115_011038_drop_shop_id_column_from_gift_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        // drops foreign key for table `{{%shop}}`
        $this->dropForeignKey(
            '{{%fk-gift-shop_id}}',
            '{{%gift}}'
        );

        // drops index for column `shop_id`
        $this->dropIndex(
            '{{%idx-gift-shop_id}}',
            '{{%gift}}'
        );

        $this->dropColumn('{{%gift}}', 'shop_id');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->addColumn('{{%gift}}', 'shop_id', $this->integer());

        // creates index for column `shop_id`
        $this->createIndex(
            '{{%idx-gift-shop_id}}',
            '{{%gift}}',
            'shop_id'
        );

        // add foreign key for table `{{%shop}}`
        $this->addForeignKey(
            '{{%fk-gift-shop_id}}',
            '{{%gift}}',
            'shop_id',
            '{{%shop}}',
            'id',
            'CASCADE'
        );
    }
}

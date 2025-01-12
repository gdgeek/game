<?php

use yii\db\Migration;

/**
 * Handles dropping columns from table `{{%manager}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%shop}}`
 */
class m250111_071714_drop_shop_id_column_from_manager_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
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

        $this->dropColumn('{{%manager}}', 'shop_id');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->addColumn('{{%manager}}', 'shop_id', $this->integer()->notNull());

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
}

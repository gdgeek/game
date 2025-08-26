<?php

use yii\db\Migration;

/**
 * Handles dropping columns from table `{{%device}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%shop}}`
 */
class m250826_065434_drop_shop_id_column_from_device_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        // drops foreign key for table `{{%shop}}`
        $this->dropForeignKey(
            '{{%fk-device-shop_id}}',
            '{{%device}}'
        );

        // drops index for column `shop_id`
        $this->dropIndex(
            '{{%idx-device-shop_id}}',
            '{{%device}}'
        );

        $this->dropColumn('{{%device}}', 'shop_id');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->addColumn('{{%device}}', 'shop_id', $this->integer());

        // creates index for column `shop_id`
        $this->createIndex(
            '{{%idx-device-shop_id}}',
            '{{%device}}',
            'shop_id'
        );

        // add foreign key for table `{{%shop}}`
        $this->addForeignKey(
            '{{%fk-device-shop_id}}',
            '{{%device}}',
            'shop_id',
            '{{%shop}}',
            'id',
            'CASCADE'
        );
    }
}

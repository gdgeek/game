<?php

use yii\db\Migration;

/**
 * Handles dropping columns from table `{{%gift}}`.
 */
class m250115_014528_drop_price_column_from_gift_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->dropColumn('{{%gift}}', 'price');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->addColumn('{{%gift}}', 'price', $this->integer());
    }
}

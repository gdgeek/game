<?php

use yii\db\Migration;

/**
 * Handles dropping columns from table `{{%shop}}`.
 */
class m250113_171808_drop_income_column_rate_column_info_column_price_column_play_time_column_expend_column_from_shop_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->dropColumn('{{%shop}}', 'income');
        $this->dropColumn('{{%shop}}', 'rate');
        $this->dropColumn('{{%shop}}', 'info');
        $this->dropColumn('{{%shop}}', 'price');
        $this->dropColumn('{{%shop}}', 'play_time');
        $this->dropColumn('{{%shop}}', 'expend');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->addColumn('{{%shop}}', 'income', $this->integer());
        $this->addColumn('{{%shop}}', 'rate', $this->integer());
        $this->addColumn('{{%shop}}', 'info', $this->json());
        $this->addColumn('{{%shop}}', 'price', $this->integer());
        $this->addColumn('{{%shop}}', 'play_time', $this->integer());
        $this->addColumn('{{%shop}}', 'expend', $this->integer());
    }
}

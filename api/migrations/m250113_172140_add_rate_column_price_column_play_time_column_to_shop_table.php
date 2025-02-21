<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%shop}}`.
 */
class m250113_172140_add_rate_column_price_column_play_time_column_to_shop_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%shop}}', 'rate', $this->integer()->notNull()->defaultValue(99));
        $this->addColumn('{{%shop}}', 'price', $this->integer()->notNull()->defaultValue(0));
        $this->addColumn('{{%shop}}', 'play_time', $this->integer()->notNull()->defaultValue(60));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%shop}}', 'rate');
        $this->dropColumn('{{%shop}}', 'price');
        $this->dropColumn('{{%shop}}', 'play_time');
    }
}

<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%shop}}`.
 */
class m250106_175819_add_play_time_column_to_shop_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%shop}}', 'play_time', $this->integer());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%shop}}', 'play_time');
    }
}

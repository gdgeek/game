<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%player}}`.
 */
class m250227_125128_add_give_column_to_player_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%player}}', 'give', $this->integer()->defaultValue(0));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%player}}', 'give');
    }
}

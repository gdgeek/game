<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%operation}}`.
 */
class m250227_082222_add_turnover_column_to_operation_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%operation}}', 'turnover', $this->integer());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%operation}}', 'turnover');
    }
}

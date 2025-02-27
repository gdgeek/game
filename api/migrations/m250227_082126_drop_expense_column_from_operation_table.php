<?php

use yii\db\Migration;

/**
 * Handles dropping columns from table `{{%operation}}`.
 */
class m250227_082126_drop_expense_column_from_operation_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->dropColumn('{{%operation}}', 'expense');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->addColumn('{{%operation}}', 'expense', $this->integer());
    }
}

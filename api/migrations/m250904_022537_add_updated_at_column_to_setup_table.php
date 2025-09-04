<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%setup}}`.
 */
class m250904_022537_add_updated_at_column_to_setup_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%setup}}', 'updated_at', $this->dateTime()->defaultExpression('CURRENT_TIMESTAMP')->notNull());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%setup}}', 'updated_at');
    }
}

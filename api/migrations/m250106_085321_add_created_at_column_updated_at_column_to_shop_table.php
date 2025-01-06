<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%shop}}`.
 */
class m250106_085321_add_created_at_column_updated_at_column_to_shop_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%shop}}', 'created_at', $this->dateTime()->defaultExpression('CURRENT_TIMESTAMP')->notNull());
        $this->addColumn('{{%shop}}', 'updated_at', $this->dateTime()->defaultExpression('CURRENT_TIMESTAMP')->notNull());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%shop}}', 'created_at');
        $this->dropColumn('{{%shop}}', 'updated_at');
    }
}

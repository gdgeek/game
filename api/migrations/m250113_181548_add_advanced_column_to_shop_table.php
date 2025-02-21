<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%shop}}`.
 */
class m250113_181548_add_advanced_column_to_shop_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%shop}}', 'advanced', $this->integer()->notNull()->defaultValue(0));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%shop}}', 'advanced');
    }
}

<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%device}}`.
 */
class m250106_075427_add_created_at_column_updated_at_column_to_device_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%device}}', 'created_at', $this->dateTime()->notNull());
        $this->addColumn('{{%device}}', 'updated_at', $this->dateTime()->notNull());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%device}}', 'created_at');
        $this->dropColumn('{{%device}}', 'updated_at');
    }
}
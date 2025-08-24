<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%file}}`.
 */
class m250824_064451_add_unlocked_column_to_file_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%file}}', 'unlocked', $this->boolean()->defaultValue(false));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%file}}', 'unlocked');
    }
}

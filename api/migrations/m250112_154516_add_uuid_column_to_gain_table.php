<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%gain}}`.
 */
class m250112_154516_add_uuid_column_to_gain_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%gain}}', 'uuid', $this->string()->unique());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%gain}}', 'uuid');
    }
}

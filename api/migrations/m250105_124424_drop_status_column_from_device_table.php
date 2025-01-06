<?php

use yii\db\Migration;

/**
 * Handles dropping columns from table `{{%device}}`.
 */
class m250105_124424_drop_status_column_from_device_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->dropColumn('{{%device}}', 'status');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->addColumn('{{%device}}', 'status', $this->integer());
    }
}

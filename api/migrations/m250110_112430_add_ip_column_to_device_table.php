<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%device}}`.
 */
class m250110_112430_add_ip_column_to_device_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%device}}', 'ip', $this->string());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%device}}', 'ip');
    }
}

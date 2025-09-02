<?php

use yii\db\Migration;

/**
 * Handles dropping columns from table `{{%device}}`.
 */
class m250902_071212_drop_setup_column_from_device_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->dropColumn('{{%device}}', 'setup');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->addColumn('{{%device}}', 'setup', $this->json());
    }
}

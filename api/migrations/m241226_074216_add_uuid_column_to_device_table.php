<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%device}}`.
 */
class m241226_074216_add_uuid_column_to_device_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%device}}', 'uuid', $this->string()->unique());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%device}}', 'uuid');
    }
}

<?php

use yii\db\Migration;

class m261023_120000_add_version_column_to_device_table extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%device}}', 'version', $this->integer()->notNull()->defaultValue(0));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%device}}', 'version');
    }
}

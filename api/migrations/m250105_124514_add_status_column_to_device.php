<?php

use yii\db\Migration;

/**
 * Class m250105_124514_add_status_column_to_device
 */
class m250105_124514_add_status_column_to_device extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        //设备状态
        //未使用 准备中 可运行 运行中 已停用

        $this->execute("ALTER TABLE `device` ADD COLUMN `status` ENUM('unused', 'ready', 'runnable', 'running', 'disabled') NOT NULL DEFAULT 'unused'");
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        echo "m250105_124514_add_status_column_to_device cannot be reverted.\n";

        $this->execute("ALTER TABLE `device` DROP COLUMN `status`");
        return false;
    }

    /*
    // Use up()/down() to run migration code without a transaction.
    public function up()
    {

    }

    public function down()
    {
        echo "m250105_124514_add_status_column_to_device cannot be reverted.\n";

        return false;
    }
    */
}

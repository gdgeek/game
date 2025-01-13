<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%record}}`.
 */
class m250112_055018_add_status_column_to_record_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        //设备状态
        //未使用 准备中 可运行 运行中 已停用

        $this->execute("ALTER TABLE `record` ADD COLUMN `status` ENUM('ready', 'running', 'finish') NOT NULL DEFAULT 'ready'");
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        echo "m250105_124514_add_status_column_to_record cannot be reverted.\n";

        $this->execute("ALTER TABLE `record` DROP COLUMN `status`");
        return true;
    }

}

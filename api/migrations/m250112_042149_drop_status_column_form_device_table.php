<?php

use yii\db\Migration;

/**
 * Handles the dropping of table `{{%status_column_form_device}}`.
 */
class m250112_042149_drop_status_column_form_device_table extends Migration
{
   
      /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->execute("ALTER TABLE `device` DROP COLUMN `status`");
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        //设备状态
        //未使用 准备中 可运行 运行中 已停用

        $this->execute("ALTER TABLE `device` ADD COLUMN `status` ENUM('unused', 'ready', 'runnable', 'running', 'disabled') NOT NULL DEFAULT 'unused'");
    }

  

}

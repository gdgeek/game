<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%award}}`.
 */
class m250114_035440_add_type_column_to_award_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->execute("ALTER TABLE `award` ADD COLUMN `type` ENUM('xl','l', 'm', 's') NOT NULL DEFAULT 's'");
  
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->execute("ALTER TABLE `award` DROP COLUMN `type`");
        return true;
    }
}

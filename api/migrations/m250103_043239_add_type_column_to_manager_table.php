<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%manager}}`.
 */
class m250103_043239_add_type_column_to_manager_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->execute("ALTER TABLE `manager` ADD COLUMN `type` ENUM('root', 'admin', 'director', 'staff') NOT NULL DEFAULT 'staff'");
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->execute("ALTER TABLE `manager` DROP COLUMN `type`");
    }
}

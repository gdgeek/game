<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%record}}`.
 */
class m250227_013204_add_game_column_to_record_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%record}}', 'game', $this->json());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%record}}', 'game');
    }
}

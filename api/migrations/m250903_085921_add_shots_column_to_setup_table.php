<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%setup}}`.
 */
class m250903_085921_add_shots_column_to_setup_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%setup}}', 'shots', $this->json());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%setup}}', 'shots');
    }
}

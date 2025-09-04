<?php

use yii\db\Migration;

/**
 * Handles dropping columns from table `{{%setup}}`.
 */
class m250904_022819_drop_shot_column_from_setup_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->dropColumn('{{%setup}}', 'shot');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->addColumn('{{%setup}}', 'shot', $this->json());
    }
}

<?php

use yii\db\Migration;

/**
 * Handles dropping columns from table `{{%record}}`.
 */
class m250227_062128_drop_points_column_award_column_from_record_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->dropColumn('{{%record}}', 'points');
        $this->dropColumn('{{%record}}', 'award');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->addColumn('{{%record}}', 'points', $this->integer());
        $this->addColumn('{{%record}}', 'award', $this->json());
    }
}

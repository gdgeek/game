<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%player}}`.
 */
class m241226_035718_add_avatar_column_info_column_to_player_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%player}}', 'avatar', $this->string());
        $this->addColumn('{{%player}}', 'info', $this->json());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%player}}', 'avatar');
        $this->dropColumn('{{%player}}', 'info');
    }
}

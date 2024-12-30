<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%player}}`.
 */
class m241225_041039_add_openid_column_to_player_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%player}}', 'openId', $this->string()->unique());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%player}}', 'openId');
    }
}

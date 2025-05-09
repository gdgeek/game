<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%checkin_recode}}`.
 */
class m250509_094320_create_checkin_recode_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {

        if ($this->db->driverName === 'mysql') {
            // http://stackoverflow.com/questions/766809/whats-the-difference-between-utf8-general-ci-and-utf8-unicode-ci
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }
        $this->createTable('{{%checkin_recode}}', [
            'id' => $this->primaryKey(),
            'key' => $this->string(),
            'openid' => $this->string(),
            'unionid' => $this->string(),
            'token' => $this->string()->unique(),
            'created_at' => $this->datetime(),
        ],$tableOptions);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%checkin_recode}}');
    }
}

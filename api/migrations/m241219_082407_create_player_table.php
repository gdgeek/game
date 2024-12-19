<?php

use yii\db\Migration;

/**
* Handles the creation of table `{{%player}}`.
*/
class m241219_082407_create_player_table extends Migration
{
    /**
    * {@inheritdoc}
    */
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            // http://stackoverflow.com/questions/766809/whats-the-difference-between-utf8-general-ci-and-utf8-unicode-ci
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }
        $this->createTable('{{%player}}', [
            'id' => $this->primaryKey(),
            'tel' => $this->string()->notNull()->unique(),
            'nickname' => $this->string(),
            'recharge' => $this->float()->defaultValue(0),
            'cost' => $this->float()->defaultValue(0),
            'times' => $this->integer()->defaultValue(0),
            'grade' => $this->integer()->defaultValue(0),
            'points' => $this->integer()->defaultValue(0),
            'created_at' => $this->dateTime()->notNull(),
            'updated_at' => $this->dateTime(),
        ], $tableOptions);
    }
    
    /**
    * {@inheritdoc}
    */
    public function safeDown()
    {
        $this->dropTable('{{%player}}');
    }
}

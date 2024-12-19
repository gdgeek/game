<?php

use yii\db\Migration;

/**
* Handles the creation of table `{{%shop}}`.
*/
class m241219_095657_create_shop_table extends Migration
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
        $this->createTable('{{%shop}}', [
            'id' => $this->primaryKey(),
            'income' => $this->float(),
            'rate' => $this->float(),
            'info' => $this->json(),
        ],$tableOptions);
    }
    
    /**
    * {@inheritdoc}
    */
    public function safeDown()
    {
        $this->dropTable('{{%shop}}');
    }
}

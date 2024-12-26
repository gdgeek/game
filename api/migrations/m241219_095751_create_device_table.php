<?php

use yii\db\Migration;

/**
* Handles the creation of table `{{%device}}`.
* Has foreign keys to the tables:
    *
    * - `{{%shop}}`
    */
    class m241219_095751_create_device_table extends Migration
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
            $this->createTable('{{%device}}', [
                'id' => $this->primaryKey(),
                'shop_id' => $this->integer(),
                'status' => $this->integer(),
            ], $tableOptions);
            
            // creates index for column `shop_id`
            $this->createIndex(
                '{{%idx-device-shop_id}}',
                '{{%device}}',
                'shop_id'
            );
            
            // add foreign key for table `{{%shop}}`
            $this->addForeignKey(
                '{{%fk-device-shop_id}}',
                '{{%device}}',
                'shop_id',
                '{{%shop}}',
                'id',
                'CASCADE'
            );
        }
        
        /**
        * {@inheritdoc}
        */
        public function safeDown()
        {
            // drops foreign key for table `{{%shop}}`
            $this->dropForeignKey(
                '{{%fk-device-shop_id}}',
                '{{%device}}'
            );
            
            // drops index for column `shop_id`
            $this->dropIndex(
                '{{%idx-device-shop_id}}',
                '{{%device}}'
            );
            
            $this->dropTable('{{%device}}');
        }
    }
    
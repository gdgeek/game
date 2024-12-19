<?php

use yii\db\Migration;

/**
* Handles the creation of table `{{%gift}}`.
* Has foreign keys to the tables:
    *
    * - `{{%shop}}`
    */
    class m241219_095724_create_gift_table extends Migration
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
            $this->createTable('{{%gift}}', [
                'id' => $this->primaryKey(),
                'shop_id' => $this->integer()->notNull(),
                'price' => $this->float(),
                'info' => $this->json(),
                'created_at' => $this->dateTime()->notNull(),
                'updated_at' => $this->dateTime(),
            ], $tableOptions);
            
            // creates index for column `shop_id`
            $this->createIndex(
                '{{%idx-gift-shop_id}}',
                '{{%gift}}',
                'shop_id'
            );
            
            // add foreign key for table `{{%shop}}`
            $this->addForeignKey(
                '{{%fk-gift-shop_id}}',
                '{{%gift}}',
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
                '{{%fk-gift-shop_id}}',
                '{{%gift}}'
            );
            
            // drops index for column `shop_id`
            $this->dropIndex(
                '{{%idx-gift-shop_id}}',
                '{{%gift}}'
            );
            
            $this->dropTable('{{%gift}}');
        }
    }
    
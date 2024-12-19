<?php

use yii\db\Migration;

/**
* Handles the creation of table `{{%daily}}`.
* Has foreign keys to the tables:
    *
    * - `{{%shop}}`
    */
    class m241219_095708_create_daily_table extends Migration
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
            $this->createTable('{{%daily}}', [
                'id' => $this->primaryKey(),
                'shop_id' => $this->integer()->notNull(),
                'income' => $this->float(),
                'expenses' => $this->float(),
                'date' => $this->date()->notNull(),
            ], $tableOptions);
            
            // creates index for column `shop_id`
            $this->createIndex(
                '{{%idx-daily-shop_id}}',
                '{{%daily}}',
                'shop_id'
            );
            
            // add foreign key for table `{{%shop}}`
            $this->addForeignKey(
                '{{%fk-daily-shop_id}}',
                '{{%daily}}',
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
                '{{%fk-daily-shop_id}}',
                '{{%daily}}'
            );
            
            // drops index for column `shop_id`
            $this->dropIndex(
                '{{%idx-daily-shop_id}}',
                '{{%daily}}'
            );
            
            $this->dropTable('{{%daily}}');
        }
    }
    
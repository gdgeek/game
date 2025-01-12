<?php

use yii\db\Migration;

/**
* Handles the creation of table `{{%record}}`.
* Has foreign keys to the tables:
    *
    * - `{{%device}}`
    * - `{{%player}}`
    */
    class m241219_095803_create_record_table extends Migration
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
            $this->createTable('{{%record}}', [
                'id' => $this->primaryKey(),
                'device_id' => $this->integer()->notNull(),
                'player_id' => $this->integer()->notNull(),
                'created_at' => $this->dateTime()->notNull(),
                'updated_at' => $this->dateTime(),
                'gift' => $this->json(),
                'award' => $this->json(),
            ], $tableOptions);
            
            // creates index for column `device_id`
            $this->createIndex(
                '{{%idx-record-device_id}}',
                '{{%record}}',
                'device_id'
            );
            
            // add foreign key for table `{{%device}}`
            $this->addForeignKey(
                '{{%fk-record-device_id}}',
                '{{%record}}',
                'device_id',
                '{{%device}}',
                'id',
                'CASCADE'
            );
            
            // creates index for column `player_id`
            $this->createIndex(
                '{{%idx-record-player_id}}',
                '{{%record}}',
                'player_id'
            );
            
            // add foreign key for table `{{%player}}`
            $this->addForeignKey(
                '{{%fk-record-player_id}}',
                '{{%record}}',
                'player_id',
                '{{%player}}',
                'id',
                'CASCADE'
            );
        }
        
        /**
        * {@inheritdoc}
        */
        public function safeDown()
        {
            // drops foreign key for table `{{%device}}`
            $this->dropForeignKey(
                '{{%fk-record-device_id}}',
                '{{%record}}'
            );
            
            // drops index for column `device_id`
            $this->dropIndex(
                '{{%idx-record-device_id}}',
                '{{%record}}'
            );
            
            // drops foreign key for table `{{%player}}`
            $this->dropForeignKey(
                '{{%fk-record-player_id}}',
                '{{%record}}'
            );
            
            // drops index for column `player_id`
            $this->dropIndex(
                '{{%idx-record-player_id}}',
                '{{%record}}'
            );
            
            $this->dropTable('{{%record}}');
        }
    }
    
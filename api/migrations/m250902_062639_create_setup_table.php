<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%setup}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%device}}`
 */
class m250902_062639_create_setup_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
          
        if ($this->db->driverName === 'mysql') {
            // http://stackoverflow.com/questions/766809/whats-the-difference-between-utf8-general-ci-and-utf8-unicode-ci
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }
        $this->createTable('{{%setup}}', [
            'id' => $this->primaryKey(),
            'money' => $this->integer()->defaultValue(0),
            'slogans' => $this->json(),
            'pictures' => $this->json(),
            'thumbs' => $this->json(),
            'shot' => $this->json(),
            'title' => $this->string(),
            'scene_id' => $this->integer(),
            'device_id' => $this->integer(),
        ],$tableOptions);

        // creates index for column `device_id`
        $this->createIndex(
            '{{%idx-setup-device_id}}',
            '{{%setup}}',
            'device_id'
        );

        // add foreign key for table `{{%device}}`
        $this->addForeignKey(
            '{{%fk-setup-device_id}}',
            '{{%setup}}',
            'device_id',
            '{{%device}}',
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
            '{{%fk-setup-device_id}}',
            '{{%setup}}'
        );

        // drops index for column `device_id`
        $this->dropIndex(
            '{{%idx-setup-device_id}}',
            '{{%setup}}'
        );

        $this->dropTable('{{%setup}}');
    }
}

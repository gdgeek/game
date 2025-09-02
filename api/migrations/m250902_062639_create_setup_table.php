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
        ]);

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

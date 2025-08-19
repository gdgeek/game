<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%file}}`.
 */
class m250812_140222_create_file_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%file}}', [
            'id' => $this->primaryKey(),
            'key' => $this->string()->unique()->notNull(),
            'type' => $this->string(),
            'md5' => $this->string()->unique(),
            'size' => $this->integer(),
            'bucket' =>  $this->string(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%file}}');
    }
}

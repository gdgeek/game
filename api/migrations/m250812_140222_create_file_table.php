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
            'unionid' => $this->string(),
            'key' => $this->string()->unique()->notNull(),
            'type' => $this->string(),
            'md5' => $this->string()->unique(),
            'size' => $this->integer(),
            'bucket' =>  $this->string(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        // 4. 为 created_at 创建普通索引（适合按创建时间排序或筛选）
        $this->createIndex(
            'idx-file-unionid', 
            'file', 
            'unionid'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%file}}');
    }
}

<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%gift}}`.
 */
class m250115_013410_add_uuid_column_tag_column_to_gift_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%gift}}', 'uuid', $this->string()->unique());
        $this->addColumn('{{%gift}}', 'tag', $this->string());
        $this->addColumn('{{%gift}}', 'picture', $this->string());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%gift}}', 'picture');
        $this->dropColumn('{{%gift}}', 'uuid');
        $this->dropColumn('{{%gift}}', 'tag');
    }
}

<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%file}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%user}}`
 */
class m250825_084920_add_title_column_user_id_column_to_file_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%file}}', 'title', $this->string());
        $this->addColumn('{{%file}}', 'user_id', $this->integer());

        // creates index for column `user_id`
        $this->createIndex(
            '{{%idx-file-user_id}}',
            '{{%file}}',
            'user_id'
        );

        // add foreign key for table `{{%user}}`
        $this->addForeignKey(
            '{{%fk-file-user_id}}',
            '{{%file}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        // drops foreign key for table `{{%user}}`
        $this->dropForeignKey(
            '{{%fk-file-user_id}}',
            '{{%file}}'
        );

        // drops index for column `user_id`
        $this->dropIndex(
            '{{%idx-file-user_id}}',
            '{{%file}}'
        );

        $this->dropColumn('{{%file}}', 'title');
        $this->dropColumn('{{%file}}', 'user_id');
    }
}

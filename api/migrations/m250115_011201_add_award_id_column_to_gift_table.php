<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%gift}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%award}}`
 */
class m250115_011201_add_award_id_column_to_gift_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%gift}}', 'award_id', $this->integer()->notNull());

        // creates index for column `award_id`
        $this->createIndex(
            '{{%idx-gift-award_id}}',
            '{{%gift}}',
            'award_id'
        );

        // add foreign key for table `{{%award}}`
        $this->addForeignKey(
            '{{%fk-gift-award_id}}',
            '{{%gift}}',
            'award_id',
            '{{%award}}',
            'id',
            'CASCADE'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        // drops foreign key for table `{{%award}}`
        $this->dropForeignKey(
            '{{%fk-gift-award_id}}',
            '{{%gift}}'
        );

        // drops index for column `award_id`
        $this->dropIndex(
            '{{%idx-gift-award_id}}',
            '{{%gift}}'
        );

        $this->dropColumn('{{%gift}}', 'award_id');
    }
}

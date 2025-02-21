<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%award}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%shop}}`
 */
class m250114_035339_create_award_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%award}}', [
            'id' => $this->primaryKey(),
            'shop_id' => $this->integer()->notNull(),
            'price' => $this->integer()->notNull()->defaultValue(100),
        ]);

        // creates index for column `shop_id`
        $this->createIndex(
            '{{%idx-award-shop_id}}',
            '{{%award}}',
            'shop_id'
        );

        // add foreign key for table `{{%shop}}`
        $this->addForeignKey(
            '{{%fk-award-shop_id}}',
            '{{%award}}',
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
            '{{%fk-award-shop_id}}',
            '{{%award}}'
        );

        // drops index for column `shop_id`
        $this->dropIndex(
            '{{%idx-award-shop_id}}',
            '{{%award}}'
        );

        $this->dropTable('{{%award}}');
    }
}

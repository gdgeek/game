<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%player_token}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%user}}`
 */
class m250103_082652_create_player_token_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%player_token}}', [
            'id' => $this->primaryKey(),
            'created_at' => $this->dateTime()->notNull(),
            'updated_at' => $this->dateTime()->notNull(),
            'expires_at' => $this->dateTime()->notNull(),
            'refresh_token' => $this->string()->notNull(),
            'player_id' => $this->integer()->notNull(),
        ]);

        // creates index for column `player_id`
        $this->createIndex(
            '{{%idx-player_token-player_id}}',
            '{{%player_token}}',
            'player_id'
        );

        // add foreign key for table `{{%user}}`
        $this->addForeignKey(
            '{{%fk-player_token-player_id}}',
            '{{%player_token}}',
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
        // drops foreign key for table `{{%player}}`
        $this->dropForeignKey(
            '{{%fk-player_token-player_id}}',
            '{{%player_token}}'
        );

        // drops index for column `player_id`
        $this->dropIndex(
            '{{%idx-player_token-player_id}}',
            '{{%player_token}}'
        );

        $this->dropTable('{{%player_token}}');
    }
}

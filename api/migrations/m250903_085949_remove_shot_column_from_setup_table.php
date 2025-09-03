<?php

use yii\db\Migration;

/**
 * Class m250903_085949_remove_shot_column_from_setup_table
 */
class m250903_085949_remove_shot_column_from_setup_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {

    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        echo "m250903_085949_remove_shot_column_from_setup_table cannot be reverted.\n";

        return false;
    }

    /*
    // Use up()/down() to run migration code without a transaction.
    public function up()
    {

    }

    public function down()
    {
        echo "m250903_085949_remove_shot_column_from_setup_table cannot be reverted.\n";

        return false;
    }
    */
}

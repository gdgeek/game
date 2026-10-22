<?php

use yii\db\Migration;

/** Snapshot the recording price in cents; unknown historical prices stay null. */
class m261023_150000_add_download_price_to_file extends Migration
{
    public function safeUp()
    {
        $price = $this->integer()->null();
        if ($this->db->driverName === 'mysql') {
            $price->comment('拍摄时的下载费用，单位分；NULL 表示历史金额未确认');
        }
        $this->addColumn('{{%file}}', 'price', $price);
        // WeChat order numbers are case-sensitive ASCII identifiers.
        $options = $this->db->driverName === 'mysql' ? 'CHARACTER SET ascii COLLATE ascii_bin ENGINE=InnoDB' : null;
        $this->createTable('{{%file_payment_order}}', [
            'out_trade_no' => $this->string(32)->notNull(),
            'user_id' => $this->integer()->notNull(),
            'file_id' => $this->integer()->notNull(),
            'amount' => $this->integer()->notNull(),
            'created_at' => $this->dateTime()->notNull(),
            'PRIMARY KEY ([[out_trade_no]])',
        ], $options);
    }

    public function safeDown()
    {
        $this->dropTable('{{%file_payment_order}}');
        $this->dropColumn('{{%file}}', 'price');
    }
}

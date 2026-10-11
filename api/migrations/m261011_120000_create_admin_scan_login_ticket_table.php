<?php

use yii\db\Migration;

class m261011_120000_create_admin_scan_login_ticket_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%admin_scan_login_ticket}}', [
            'id' => $this->char(32)->notNull(),
            'secret_hash' => $this->char(64)->notNull(),
            'owner_hash' => $this->char(64)->notNull(),
            'audience' => $this->string(255)->notNull(),
            'status' => $this->string(16)->notNull(),
            'approved_user_id' => $this->integer()->null(),
            'created_at' => $this->integer()->notNull(),
            'expires_at' => $this->integer()->notNull(),
            'approved_at' => $this->integer()->null(),
            'consumed_at' => $this->integer()->null(),
            'PRIMARY KEY ([[id]])',
        ]);
        $this->createIndex('idx-admin-scan-login-expiry', '{{%admin_scan_login_ticket}}', 'expires_at');
    }

    public function safeDown()
    {
        $this->dropTable('{{%admin_scan_login_ticket}}');
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateRefundApprovals extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('refund_approvals')) {
            $table = $this->table('refund_approvals');
            $table
                ->addColumn('refund_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('user_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => true, 'default' => null])
                ->addColumn('token_expires_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'Pendiente', 'null' => false])
                ->addColumn('responded_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('observations', 'text', ['null' => true, 'default' => null])
                ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true, 'default' => null])
                ->addColumn('user_agent', 'text', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_refund_approvals_token_hash'])
                ->addIndex(['refund_id'])
                ->addIndex(['user_id'])
                ->addForeignKey('refund_id', 'refunds', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('refund_approvals')) {
            $this->table('refund_approvals')->drop()->save();
        }
    }
}

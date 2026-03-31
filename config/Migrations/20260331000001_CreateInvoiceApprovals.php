<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateInvoiceApprovals extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('invoice_approvals')) {
            $table = $this->table('invoice_approvals');
            $table
                ->addColumn('invoice_id', 'integer', ['null' => false])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('token', 'string', ['limit' => 64, 'null' => true, 'default' => null])
                ->addColumn('token_expires_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'Pendiente', 'null' => false])
                ->addColumn('responded_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('observations', 'text', ['null' => true, 'default' => null])
                ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true, 'default' => null])
                ->addColumn('user_agent', 'text', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['token'], ['unique' => true])
                ->addIndex(['invoice_id'])
                ->addIndex(['user_id'])
                ->addForeignKey('invoice_id', 'invoices', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('invoice_approvals')) {
            $this->table('invoice_approvals')->drop()->save();
        }
    }
}

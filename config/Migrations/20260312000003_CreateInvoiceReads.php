<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateInvoiceReads extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('invoice_reads')) {
            return;
        }

        $table = $this->table('invoice_reads');
        $table
            ->addColumn('invoice_id', 'integer', ['null' => false, 'signed' => true])
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => true])
            ->addColumn('last_visited_at', 'datetime', ['null' => false])
            ->addIndex(['invoice_id', 'user_id'], ['unique' => true, 'name' => 'uq_invoice_reads'])
            ->addForeignKey('invoice_id', 'invoices', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('invoice_reads')->drop()->save();
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddInvoiceNumberToInvoices extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoices')
            ->addColumn('invoice_number', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => null,
                'after' => 'id',
            ])
            ->addIndex(['invoice_number'], ['unique' => true, 'name' => 'uq_invoices_invoice_number'])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoices')
            ->removeIndex(['invoice_number'])
            ->removeColumn('invoice_number')
            ->update();
    }
}

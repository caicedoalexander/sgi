<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddStatusEnumToInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoice_payments')
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'authorized', 'rejected'],
                'default' => 'pending',
                'null' => false,
                'after' => 'authorized',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoice_payments')->removeColumn('status')->update();
    }
}

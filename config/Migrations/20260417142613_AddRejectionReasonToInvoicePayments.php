<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddRejectionReasonToInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoice_payments')
            ->addColumn('rejection_reason', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'status',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoice_payments')->removeColumn('rejection_reason')->update();
    }
}

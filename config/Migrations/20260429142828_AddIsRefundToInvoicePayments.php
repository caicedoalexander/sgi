<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddIsRefundToInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoice_payments');
        if (!$table->hasColumn('is_refund')) {
            $table
                ->addColumn('is_refund', 'boolean', [
                    'null' => false,
                    'default' => false,
                    'after' => 'rejection_reason',
                ])
                ->addIndex(['is_refund'])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('invoice_payments');
        if ($table->hasColumn('is_refund')) {
            $table->removeIndexByName('invoice_payments_is_refund')->update();
            $table->removeColumn('is_refund')->update();
        }
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddRecordFksToInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoice_payments')
            ->addColumn('petty_cash_record_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_scheduling_id',
            ])
            ->addForeignKey('petty_cash_record_id', 'petty_cash_records', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoice_payments')
            ->dropForeignKey('petty_cash_record_id')
            ->removeColumn('petty_cash_record_id')
            ->update();
    }
}

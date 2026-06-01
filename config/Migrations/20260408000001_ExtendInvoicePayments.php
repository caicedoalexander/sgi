<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class ExtendInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoice_payments')
            ->addColumn('payment_scheduling_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_date',
            ])
            ->addColumn('authorized', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'payment_scheduling_id',
            ])
            ->addColumn('authorized_by', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'authorized',
            ])
            ->addColumn('authorized_date', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'authorized_by',
            ])
            ->addForeignKey('authorized_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoice_payments')
            ->dropForeignKey('authorized_by')
            ->removeColumn('payment_scheduling_id')
            ->removeColumn('authorized')
            ->removeColumn('authorized_by')
            ->removeColumn('authorized_date')
            ->update();
    }
}

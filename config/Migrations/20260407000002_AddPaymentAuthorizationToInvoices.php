<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPaymentAuthorizationToInvoices extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoices')
            ->addColumn('payment_authorized', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'payment_date',
            ])
            ->addColumn('payment_authorized_by', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_authorized',
            ])
            ->addColumn('payment_authorized_date', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'payment_authorized_by',
            ])
            ->addForeignKey('payment_authorized_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoices')
            ->dropForeignKey('payment_authorized_by')
            ->removeColumn('payment_authorized')
            ->removeColumn('payment_authorized_by')
            ->removeColumn('payment_authorized_date')
            ->update();
    }
}

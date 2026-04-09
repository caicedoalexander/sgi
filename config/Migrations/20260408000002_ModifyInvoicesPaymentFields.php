<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class ModifyInvoicesPaymentFields extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoices')
            ->renameColumn('payment_date', 'full_payment_date')
            ->update();

        $this->table('invoices')
            ->dropForeignKey('payment_authorized_by')
            ->update();

        $this->table('invoices')
            ->removeColumn('payment_authorized')
            ->removeColumn('payment_authorized_by')
            ->removeColumn('payment_authorized_date')
            ->update();
    }

    public function down(): void
    {
        $this->table('invoices')
            ->renameColumn('full_payment_date', 'payment_date')
            ->update();

        $this->table('invoices')
            ->addColumn('payment_authorized', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'full_payment_date',
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
}

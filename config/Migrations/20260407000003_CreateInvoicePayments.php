<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('invoice_payments')) {
            $this->table('invoice_payments')
                ->addColumn('invoice_id', 'integer', ['null' => false])
                ->addColumn('banking_entity_id', 'integer', ['null' => false])
                ->addColumn('amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => false])
                ->addColumn('payment_date', 'date', ['null' => false])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['invoice_id'])
                ->addForeignKey('invoice_id', 'invoices', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('created_by', 'users', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('invoice_payments')) {
            $this->table('invoice_payments')->drop()->save();
        }
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateInvoiceObservations extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoice_observations')
            ->addColumn('invoice_id', 'integer', [
                'null' => false,
            ])
            ->addColumn('user_id', 'integer', [
                'null' => false,
            ])
            ->addColumn('message', 'text', [
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addForeignKey('invoice_id', 'invoices', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('invoice_observations')->drop()->save();
    }
}

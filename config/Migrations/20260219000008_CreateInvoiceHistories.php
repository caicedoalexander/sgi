<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateInvoiceHistories extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('invoice_histories');
        $table->addColumn('invoice_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('user_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('field_changed', 'string', [
            'limit' => 100,
            'null' => false,
        ]);
        $table->addColumn('old_value', 'text', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('new_value', 'text', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('created', 'datetime', [
            'null' => true,
            'default' => null,
        ]);
        $table->addForeignKey('invoice_id', 'invoices', 'id', [
            'delete' => 'CASCADE',
            'update' => 'NO_ACTION',
        ]);
        $table->addForeignKey('user_id', 'users', 'id', [
            'delete' => 'RESTRICT',
            'update' => 'NO_ACTION',
        ]);
        $table->create();
    }
}

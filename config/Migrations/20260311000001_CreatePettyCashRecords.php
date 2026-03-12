<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePettyCashRecords extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('petty_cash_records');

        $table->addColumn('code', 'string', [
            'limit' => 30,
            'null' => false,
        ]);
        $table->addColumn('status', 'string', [
            'limit' => 20,
            'null' => false,
            'default' => 'borrador',
        ]);
        $table->addColumn('total_amount', 'decimal', [
            'precision' => 15,
            'scale' => 2,
            'null' => false,
            'default' => 0,
        ]);
        $table->addColumn('notes', 'text', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('created_by', 'integer', [
            'null' => false,
            'signed' => true,
        ]);
        $table->addColumn('created', 'datetime', [
            'null' => true,
            'default' => null,
        ]);
        $table->addColumn('modified', 'datetime', [
            'null' => true,
            'default' => null,
        ]);

        $table->addIndex(['code'], ['unique' => true]);
        $table->addForeignKey('created_by', 'users', 'id', [
            'delete' => 'RESTRICT',
            'update' => 'CASCADE',
        ]);

        $table->create();
    }
}

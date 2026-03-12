<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPettyCashRecordIdToInvoices extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('invoices');

        $table->addColumn('petty_cash_record_id', 'integer', [
            'null' => true,
            'default' => null,
            'signed' => true,
            'after' => 'registered_by',
        ]);
        $table->addForeignKey('petty_cash_record_id', 'petty_cash_records', 'id', [
            'delete' => 'SET_NULL',
            'update' => 'CASCADE',
        ]);

        $table->update();
    }
}

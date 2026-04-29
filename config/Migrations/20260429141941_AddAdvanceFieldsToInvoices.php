<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddAdvanceFieldsToInvoices extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoices');
        if (!$table->hasColumn('advance_id')) {
            $table
                ->addColumn('advance_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'after' => 'petty_cash_record_id',
                    'signed' => true,
                ])
                ->addIndex(['advance_id'])
                ->addForeignKey('advance_id', 'invoices', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'NO_ACTION',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('invoices');
        if ($table->hasColumn('advance_id')) {
            $table->dropForeignKey('advance_id')->update();
            $table->removeColumn('advance_id')->update();
        }
    }
}

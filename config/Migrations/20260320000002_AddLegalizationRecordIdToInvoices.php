<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddLegalizationRecordIdToInvoices extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoices');
        if (!$table->hasColumn('legalization_record_id')) {
            $table
                ->addColumn('legalization_record_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => true,
                    'after' => 'petty_cash_record_id',
                ])
                ->addForeignKey('legalization_record_id', 'legalization_records', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('invoices');
        if ($table->hasForeignKey('legalization_record_id')) {
            $table->dropForeignKey('legalization_record_id');
        }
        if ($table->hasColumn('legalization_record_id')) {
            $table->removeColumn('legalization_record_id')->update();
        }
    }
}

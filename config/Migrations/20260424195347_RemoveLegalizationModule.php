<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RemoveLegalizationModule extends BaseMigration
{
    public function up(): void
    {
        $invoicePayments = $this->table('invoice_payments');
        if ($invoicePayments->hasForeignKey('legalization_record_id')) {
            $invoicePayments->dropForeignKey('legalization_record_id')->update();
        }
        if ($invoicePayments->hasColumn('legalization_record_id')) {
            $invoicePayments->removeColumn('legalization_record_id')->update();
        }

        $invoices = $this->table('invoices');
        if ($invoices->hasForeignKey('legalization_record_id')) {
            $invoices->dropForeignKey('legalization_record_id')->update();
        }
        if ($invoices->hasColumn('legalization_record_id')) {
            $invoices->removeColumn('legalization_record_id')->update();
        }

        foreach (['legalization_payments', 'legalization_observations', 'legalization_documents', 'legalization_records'] as $tableName) {
            if ($this->hasTable($tableName)) {
                $this->table($tableName)->drop()->update();
            }
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('Irreversible: modulo Legalizaciones eliminado.');
    }
}

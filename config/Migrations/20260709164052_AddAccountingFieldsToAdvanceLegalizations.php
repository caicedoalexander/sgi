<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddAccountingFieldsToAdvanceLegalizations extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('advance_legalizations')) {
            return;
        }

        $table = $this->table('advance_legalizations');
        $table->addColumn('accrued', 'boolean', [
            'null' => false,
            'default' => false,
            'after' => 'case_type',
        ]);
        $table->addColumn('accrual_date', 'date', [
            'null' => true,
            'default' => null,
            'after' => 'accrued',
        ]);
        $table->addColumn('ready_for_payment', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => null,
            'after' => 'accrual_date',
        ]);
        $table->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('advance_legalizations')) {
            return;
        }

        $table = $this->table('advance_legalizations');
        $table->removeColumn('accrued');
        $table->removeColumn('accrual_date');
        $table->removeColumn('ready_for_payment');
        $table->update();
    }
}

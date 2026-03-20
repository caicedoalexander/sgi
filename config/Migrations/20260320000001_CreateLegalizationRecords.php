<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLegalizationRecords extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('legalization_records')) {
            $table = $this->table('legalization_records');
            $table
                ->addColumn('code', 'string', ['limit' => 30, 'null' => true, 'default' => null])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'agrupacion'])
                ->addColumn('total_amount', 'decimal', ['precision' => 15, 'scale' => 2, 'default' => 0])
                ->addColumn('accrued', 'boolean', ['default' => false])
                ->addColumn('accrual_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('ready_for_payment', 'string', ['limit' => 50, 'null' => true, 'default' => null])
                ->addColumn('payment_status', 'string', ['limit' => 30, 'null' => true, 'default' => null])
                ->addColumn('payment_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('notes', 'text', ['null' => true, 'default' => null])
                ->addColumn('created_by', 'integer', ['signed' => true])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addForeignKey('created_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('legalization_records')) {
            $this->table('legalization_records')->drop()->save();
        }
    }
}

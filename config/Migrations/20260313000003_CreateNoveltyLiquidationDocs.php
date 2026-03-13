<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyLiquidationDocs extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_liquidation_docs')) {
            $this->table('novelty_liquidation_docs')
                ->addColumn('liquidation_number', 'string', ['limit' => 50, 'null' => false])
                ->addColumn('period', 'string', ['limit' => 30, 'null' => false])
                ->addColumn('pipeline_status', 'string', ['limit' => 30, 'null' => false, 'default' => 'contabilidad'])
                ->addColumn('document_date', 'date', ['null' => false])
                ->addColumn('performed_by', 'integer', ['null' => false])
                ->addColumn('passes_for_payment', 'boolean', ['null' => true, 'default' => null])
                ->addColumn('payment_status', 'string', ['limit' => 20, 'null' => true, 'default' => null])
                ->addColumn('payment_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['liquidation_number'], ['unique' => true])
                ->addForeignKey('performed_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->addForeignKey('created_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->create();
        }

        // Now add FK from employee_novelties → novelty_liquidation_docs
        $this->table('employee_novelties')
            ->addForeignKey('liquidation_doc_id', 'novelty_liquidation_docs', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        try {
            $this->table('employee_novelties')->dropForeignKey('liquidation_doc_id')->update();
        } catch (\Exception $e) {
            // FK may not exist
        }
        if ($this->hasTable('novelty_liquidation_docs')) {
            $this->table('novelty_liquidation_docs')->drop()->save();
        }
    }
}

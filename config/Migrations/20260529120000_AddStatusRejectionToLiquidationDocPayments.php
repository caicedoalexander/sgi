<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddStatusRejectionToLiquidationDocPayments extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('liquidation_doc_payments')) {
            return;
        }

        $this->table('liquidation_doc_payments')
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'authorized', 'rejected'],
                'default' => 'pending',
                'null' => false,
                'after' => 'authorized',
            ])
            ->addColumn('rejection_reason', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'status',
            ])
            ->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('liquidation_doc_payments')) {
            return;
        }

        $this->table('liquidation_doc_payments')
            ->removeColumn('rejection_reason')
            ->removeColumn('status')
            ->update();
    }
}

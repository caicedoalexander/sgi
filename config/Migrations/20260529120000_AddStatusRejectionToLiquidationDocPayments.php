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

        // Backfill: las filas previamente autorizadas quedan como 'authorized'.
        // Las filas con authorized=0 conservan el default 'pending'. Las filas
        // históricas rechazadas no existen (el flujo previo las borraba); es
        // esperado que no haya nada que backfillear hacia 'rejected'.
        $this->execute("UPDATE liquidation_doc_payments SET status = 'authorized' WHERE authorized = 1");
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

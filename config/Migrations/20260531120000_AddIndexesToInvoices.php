<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddIndexesToInvoices extends BaseMigration
{
    /**
     * Índices para las columnas de filtro caliente de `invoices`, identificadas
     * en la auditoría de performance (M4). `pipeline_status` se filtra en el
     * dashboard (InvoiceStatisticsService), los listados (_visibleStatusConditions)
     * y los COUNT del sidebar (SidebarCounterService) — sin índice era full scan.
     *
     * - idx_invoices_status_created: compuesto, cubre filtros por estado y los
     *   rangos de fecha del dashboard (pipeline_status líder + created).
     * - idx_invoices_area_approval: filtro de aprobación de área.
     * - idx_invoices_due_date: query de facturas vencidas.
     */
    public function up(): void
    {
        if (!$this->hasTable('invoices')) {
            return;
        }

        $table = $this->table('invoices');

        if (!$table->hasIndexByName('idx_invoices_status_created')) {
            $table
                ->addIndex(
                    ['pipeline_status', 'created'],
                    ['name' => 'idx_invoices_status_created'],
                )
                ->update();
        }

        if (!$table->hasIndexByName('idx_invoices_area_approval')) {
            $table
                ->addIndex(
                    ['area_approval'],
                    ['name' => 'idx_invoices_area_approval'],
                )
                ->update();
        }

        if (!$table->hasIndexByName('idx_invoices_due_date')) {
            $table
                ->addIndex(
                    ['due_date'],
                    ['name' => 'idx_invoices_due_date'],
                )
                ->update();
        }
    }

    /**
     * Reverse the migration: drop the three indexes if present.
     */
    public function down(): void
    {
        if (!$this->hasTable('invoices')) {
            return;
        }

        $table = $this->table('invoices');

        if ($table->hasIndexByName('idx_invoices_status_created')) {
            $table->removeIndexByName('idx_invoices_status_created')->update();
        }

        if ($table->hasIndexByName('idx_invoices_area_approval')) {
            $table->removeIndexByName('idx_invoices_area_approval')->update();
        }

        if ($table->hasIndexByName('idx_invoices_due_date')) {
            $table->removeIndexByName('idx_invoices_due_date')->update();
        }
    }
}

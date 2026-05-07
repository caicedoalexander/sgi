<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddIndexesToEmployeeNovelties extends BaseMigration
{
    /**
     * Indices compuestos para soportar la query de novedad activa hoy:
     *
     * SELECT ... FROM employee_novelties WHERE
     *   pipeline_status != 'Rechazada' AND (
     *     (permission_date = TODAY AND start_date IS NULL)
     *     OR (start_date <= TODAY AND end_date >= TODAY)
     *   )
     *
     * - idx_novelty_pipeline_dates cubre el rango multi-dia.
     * - idx_novelty_permission_date cubre el caso single-day.
     */
    public function up(): void
    {
        $table = $this->table('employee_novelties');

        if (!$table->hasIndexByName('idx_novelty_pipeline_dates')) {
            $table
                ->addIndex(
                    ['pipeline_status', 'start_date', 'end_date'],
                    ['name' => 'idx_novelty_pipeline_dates'],
                )
                ->update();
        }

        if (!$table->hasIndexByName('idx_novelty_permission_date')) {
            $table
                ->addIndex(
                    ['permission_date'],
                    ['name' => 'idx_novelty_permission_date'],
                )
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('employee_novelties');

        if ($table->hasIndexByName('idx_novelty_pipeline_dates')) {
            $table->removeIndexByName('idx_novelty_pipeline_dates')->update();
        }

        if ($table->hasIndexByName('idx_novelty_permission_date')) {
            $table->removeIndexByName('idx_novelty_permission_date')->update();
        }
    }
}

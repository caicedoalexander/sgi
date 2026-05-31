<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddIndexesToEmployeeStats extends BaseMigration
{
    /**
     * Índices para las queries de estadísticas de RRHH del dashboard (B9):
     *
     * - employees(status, contract_type): cubre el filtro por `status` (getBasicStats,
     *   getExtendedStats) y el GROUP BY contract_type de getChartData con un solo índice
     *   (status como columna líder).
     * - employee_novelties(created): acelera el rango y GROUP BY mensual de getChartData
     *   y el COUNT de novedades del mes de getBasicStats.
     */
    public function up(): void
    {
        if ($this->hasTable('employees')) {
            $employees = $this->table('employees');
            if (!$employees->hasIndexByName('idx_employees_status_contract')) {
                $employees
                    ->addIndex(
                        ['status', 'contract_type'],
                        ['name' => 'idx_employees_status_contract'],
                    )
                    ->update();
            }
        }

        if ($this->hasTable('employee_novelties')) {
            $novelties = $this->table('employee_novelties');
            if (!$novelties->hasIndexByName('idx_novelties_created')) {
                $novelties
                    ->addIndex(
                        ['created'],
                        ['name' => 'idx_novelties_created'],
                    )
                    ->update();
            }
        }
    }

    /**
     * Reverse the migration: drop both indexes if present.
     */
    public function down(): void
    {
        if ($this->hasTable('employees')) {
            $employees = $this->table('employees');
            if ($employees->hasIndexByName('idx_employees_status_contract')) {
                $employees->removeIndexByName('idx_employees_status_contract')->update();
            }
        }

        if ($this->hasTable('employee_novelties')) {
            $novelties = $this->table('employee_novelties');
            if ($novelties->hasIndexByName('idx_novelties_created')) {
                $novelties->removeIndexByName('idx_novelties_created')->update();
            }
        }
    }
}

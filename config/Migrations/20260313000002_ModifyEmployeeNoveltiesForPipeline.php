<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class ModifyEmployeeNoveltiesForPipeline extends BaseMigration
{
    public function up(): void
    {
        // Rename status → pipeline_status and widen
        $this->execute("ALTER TABLE employee_novelties CHANGE COLUMN `status` `pipeline_status` VARCHAR(30) NOT NULL DEFAULT 'registro'");

        // Migrate old values
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'registro' WHERE pipeline_status = 'pendiente'");
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'pagada' WHERE pipeline_status = 'aprobado'");
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'rechazada' WHERE pipeline_status = 'rechazado'");

        // Make employee_id nullable (for massive novelties)
        $this->execute("ALTER TABLE employee_novelties MODIFY COLUMN employee_id INTEGER NULL DEFAULT NULL");

        // Add new columns
        $table = $this->table('employee_novelties');
        $table
            ->addColumn('passes_payroll', 'boolean', ['null' => true, 'default' => null, 'after' => 'observations'])
            ->addColumn('rrhh_by', 'integer', ['null' => true, 'default' => null, 'after' => 'passes_payroll'])
            ->addColumn('liquidation_doc_id', 'integer', ['null' => true, 'default' => null, 'after' => 'rrhh_by'])
            ->addColumn('custom_name', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'liquidation_doc_id'])
            ->addForeignKey('rrhh_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();

        // Note: liquidation_doc_id FK added after creating novelty_liquidation_docs table
    }

    public function down(): void
    {
        $table = $this->table('employee_novelties');

        // Remove FK first if exists
        try {
            $table->dropForeignKey('rrhh_by')->update();
        } catch (\Exception $e) {
            // FK may not exist
        }

        $table
            ->removeColumn('passes_payroll')
            ->removeColumn('rrhh_by')
            ->removeColumn('liquidation_doc_id')
            ->removeColumn('custom_name')
            ->update();

        $this->execute("ALTER TABLE employee_novelties MODIFY COLUMN employee_id INTEGER NOT NULL");
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'pendiente' WHERE pipeline_status = 'registro'");
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'aprobado' WHERE pipeline_status = 'pagada'");
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'rechazado' WHERE pipeline_status = 'rechazada'");
        $this->execute("ALTER TABLE employee_novelties CHANGE COLUMN `pipeline_status` `status` VARCHAR(20) NOT NULL DEFAULT 'pendiente'");
    }
}

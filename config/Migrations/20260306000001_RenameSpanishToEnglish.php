<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RenameSpanishToEnglish extends BaseMigration
{
    public function up(): void
    {
        // 1. Rename columns in employees table
        //    Drop FK first, rename columns, then re-add FK after table rename
        $employees = $this->table('employees');
        $employees->dropForeignKey('organizacion_temporal_id')->save();

        $this->execute("ALTER TABLE employees CHANGE tipo_contrato contract_type VARCHAR(20) DEFAULT NULL");
        $this->execute("ALTER TABLE employees CHANGE organizacion_temporal_id temporary_organization_id INT DEFAULT NULL");
        $this->execute("ALTER TABLE employees CHANGE chaleco vest_number VARCHAR(20) DEFAULT NULL");

        // 2. Rename organizaciones_temporales table
        $this->execute("RENAME TABLE organizaciones_temporales TO temporary_organizations");

        // Re-add FK after table rename
        $employees->addForeignKey('temporary_organization_id', 'temporary_organizations', 'id', [
            'delete' => 'SET_NULL',
            'update' => 'CASCADE',
        ])->update();

        // 3. Rename employee_novedades table and its column
        $this->execute("ALTER TABLE employee_novedades CHANGE novedad_type incident_type VARCHAR(50) NOT NULL");
        $this->execute("RENAME TABLE employee_novedades TO employee_incidents");

        // 4. Rename columns in employee_leaves table
        $this->execute("ALTER TABLE employee_leaves CHANGE fecha_permiso leave_date DATE DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE fecha_diligenciamiento filing_date DATE DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE hora_salida departure_time TIME DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE hora_entrada return_time TIME DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE cantidad_dias days_count INT DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE remunerado paid TINYINT(1) DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE firma_path signature_path VARCHAR(500) DEFAULT NULL");

        // 5. Rename remunerado in leave_types table
        $this->execute("ALTER TABLE leave_types CHANGE remunerado paid TINYINT(1) NOT NULL DEFAULT 0");
    }

    public function down(): void
    {
        // Reverse leave_types
        $this->execute("ALTER TABLE leave_types CHANGE paid remunerado TINYINT(1) NOT NULL DEFAULT 0");

        // Reverse employee_leaves
        $this->execute("ALTER TABLE employee_leaves CHANGE leave_date fecha_permiso DATE DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE filing_date fecha_diligenciamiento DATE DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE departure_time hora_salida TIME DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE return_time hora_entrada TIME DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE days_count cantidad_dias INT DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE paid remunerado TINYINT(1) DEFAULT NULL");
        $this->execute("ALTER TABLE employee_leaves CHANGE signature_path firma_path VARCHAR(500) DEFAULT NULL");

        // Reverse employee_incidents
        $this->execute("RENAME TABLE employee_incidents TO employee_novedades");
        $this->execute("ALTER TABLE employee_novedades CHANGE incident_type novedad_type VARCHAR(50) NOT NULL");

        // Reverse temporary_organizations
        $employees = $this->table('employees');
        $employees->dropForeignKey('temporary_organization_id')->save();

        $this->execute("RENAME TABLE temporary_organizations TO organizaciones_temporales");

        // Reverse employees columns
        $this->execute("ALTER TABLE employees CHANGE contract_type tipo_contrato VARCHAR(20) DEFAULT NULL");
        $this->execute("ALTER TABLE employees CHANGE temporary_organization_id organizacion_temporal_id INT DEFAULT NULL");
        $this->execute("ALTER TABLE employees CHANGE vest_number chaleco VARCHAR(20) DEFAULT NULL");

        $employees->addForeignKey('organizacion_temporal_id', 'organizaciones_temporales', 'id', [
            'delete' => 'SET_NULL',
            'update' => 'CASCADE',
        ])->update();
    }
}

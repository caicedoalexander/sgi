<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class ReplaceEmployeeStatusesTableWithStatusColumn extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        // 1. Add status column with safe default.
        $this->execute("ALTER TABLE employees ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'activo' AFTER employee_status_id");

        // 2. Backfill from employee_status_id (1=Activo, 2=Retirado). NULLs become default 'activo'.
        $this->execute("UPDATE employees SET status = CASE employee_status_id WHEN 2 THEN 'retirado' ELSE 'activo' END");

        // 3. Drop FK and column.
        $this->execute('ALTER TABLE employees DROP FOREIGN KEY employees_ibfk_3');
        $this->execute('ALTER TABLE employees DROP COLUMN employee_status_id');

        // 4. Drop the catalog table — replaced by the status enum column.
        $this->execute('DROP TABLE employee_statuses');

        // 5. Clean up RBAC permissions for the removed module.
        $this->execute("DELETE FROM permissions WHERE module = 'employee_statuses'");
    }

    /**
     * @return void
     */
    public function down(): void
    {
        // 1. Recreate employee_statuses catalog with the original two rows.
        $this->execute('CREATE TABLE employee_statuses (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            created DATETIME NULL,
            modified DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->execute("INSERT INTO employee_statuses (id, name, created, modified) VALUES (1, 'Activo', NOW(), NOW()), (2, 'Retirado', NOW(), NOW())");

        // 2. Re-add column and FK (position not preserved on rollback — functional reverse only).
        $this->execute('ALTER TABLE employees ADD COLUMN employee_status_id INT(11) NULL');
        $this->execute("UPDATE employees SET employee_status_id = CASE status WHEN 'retirado' THEN 2 ELSE 1 END");
        $this->execute('ALTER TABLE employees ADD CONSTRAINT employees_ibfk_3 FOREIGN KEY (employee_status_id) REFERENCES employee_statuses (id) ON DELETE SET NULL ON UPDATE CASCADE');

        // 3. Drop the status column.
        $this->execute('ALTER TABLE employees DROP COLUMN status');
    }
}

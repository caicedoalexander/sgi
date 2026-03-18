<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MigrateEmployeeNotesAndDropColumn extends BaseMigration
{
    public function up(): void
    {
        // Migrate existing notes to employee_observations
        $employees = $this->fetchAll(
            "SELECT id, notes, created FROM employees WHERE notes IS NOT NULL AND TRIM(notes) != ''"
        );

        foreach ($employees as $emp) {
            $this->execute(sprintf(
                "INSERT INTO employee_observations (employee_id, user_id, message, created) VALUES (%d, 1, %s, %s)",
                $emp['id'],
                $this->getAdapter()->getConnection()->quote($emp['notes']),
                $emp['created'] ? $this->getAdapter()->getConnection()->quote($emp['created']) : 'NOW()'
            ));
        }

        // Drop the notes column
        $this->table('employees')
            ->removeColumn('notes')
            ->update();
    }

    public function down(): void
    {
        $this->table('employees')
            ->addColumn('notes', 'text', ['null' => true, 'default' => null, 'after' => 'severance_fund'])
            ->update();
    }
}

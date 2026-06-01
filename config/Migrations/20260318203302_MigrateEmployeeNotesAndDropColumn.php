<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MigrateEmployeeNotesAndDropColumn extends BaseMigration
{
    public function up(): void
    {
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

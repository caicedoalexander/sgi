<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyMassiveEmployees extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_massive_employees')) {
            $this->table('novelty_massive_employees')
                ->addColumn('novelty_id', 'integer', ['null' => false])
                ->addColumn('employee_id', 'integer', ['null' => false])
                ->addForeignKey('novelty_id', 'employee_novelties', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('employee_id', 'employees', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_massive_employees')) {
            $this->table('novelty_massive_employees')->drop()->save();
        }
    }
}

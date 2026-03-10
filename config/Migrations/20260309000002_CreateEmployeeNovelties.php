<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateEmployeeNovelties extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('employee_novelties')) {
            $this->table('employee_novelties')
                ->addColumn('employee_id', 'integer', ['null' => false])
                ->addColumn('novelty_type_id', 'integer', ['null' => false])
                ->addColumn('filing_date', 'date', ['null' => false])
                ->addColumn('permission_date', 'date', ['null' => false])
                ->addColumn('schedule_type', 'string', ['limit' => 10, 'null' => false])
                ->addColumn('start_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('end_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('start_time', 'time', ['null' => true, 'default' => null])
                ->addColumn('end_time', 'time', ['null' => true, 'default' => null])
                ->addColumn('is_paid', 'boolean', ['null' => false, 'default' => false])
                ->addColumn('reason', 'text', ['null' => false])
                ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'pendiente'])
                ->addColumn('approved_by', 'integer', ['null' => true, 'default' => null])
                ->addColumn('approved_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('registered_by', 'integer', ['null' => false])
                ->addColumn('employee_signature', 'string', ['limit' => 255, 'null' => true, 'default' => null])
                ->addColumn('coordinator_signature', 'string', ['limit' => 255, 'null' => true, 'default' => null])
                ->addColumn('observations', 'text', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addForeignKey('employee_id', 'employees', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('novelty_type_id', 'novelty_types', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->addForeignKey('approved_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
                ->addForeignKey('registered_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('employee_novelties')) {
            $this->table('employee_novelties')->drop()->save();
        }
    }
}

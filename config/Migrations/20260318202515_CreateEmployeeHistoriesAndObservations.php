<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateEmployeeHistoriesAndObservations extends BaseMigration
{
    public function up(): void
    {
        $this->table('employee_histories')
            ->addColumn('employee_id', 'integer', ['null' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('field_changed', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('old_value', 'text', ['null' => true, 'default' => null])
            ->addColumn('new_value', 'text', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addForeignKey('employee_id', 'employees', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('employee_observations')
            ->addColumn('employee_id', 'integer', ['null' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('message', 'text', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addForeignKey('employee_id', 'employees', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('employee_observations')->drop()->save();
        $this->table('employee_histories')->drop()->save();
    }
}

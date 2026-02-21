<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateEmployeeLeaves extends BaseMigration
{
    public function up(): void
    {
        $this->table('employee_leaves')
            ->addColumn('employee_id', 'integer', ['null' => false])
            ->addColumn('leave_type_id', 'integer', ['null' => false])
            ->addColumn('start_date', 'date', ['null' => false])
            ->addColumn('end_date', 'date', ['null' => false])
            ->addColumn('status', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'pendiente',
            ])
            ->addColumn('observations', 'text', ['null' => true])
            ->addColumn('approved_by', 'integer', ['null' => true])
            ->addColumn('approved_at', 'datetime', ['null' => true])
            ->addColumn('requested_by', 'integer', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addForeignKey('employee_id', 'employees', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('leave_type_id', 'leave_types', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('approved_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('requested_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('employee_leaves')->drop()->save();
    }
}

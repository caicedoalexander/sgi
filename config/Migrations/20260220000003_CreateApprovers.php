<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateApprovers extends BaseMigration
{
    public function up(): void
    {
        // Drop if partially created from a previous failed migration
        if ($this->hasTable('approvers')) {
            $this->table('approvers')->drop()->save();
        }

        $this->table('approvers')
            ->addColumn('user_id', 'integer', [
                'null' => false,
            ])
            ->addColumn('operation_center_id', 'integer', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('active', 'boolean', [
                'null' => false,
                'default' => true,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['user_id'])
            ->addIndex(['operation_center_id'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('operation_center_id', 'operation_centers', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->create();

        $this->table('invoices')
            ->addColumn('approver_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'registered_by',
            ])
            ->addIndex(['approver_id'])
            ->addForeignKey('approver_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoices')
            ->dropForeignKey('approver_id')
            ->removeIndex(['approver_id'])
            ->removeColumn('approver_id')
            ->update();

        $this->table('approvers')->drop()->save();
    }
}

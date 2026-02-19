<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePermissions extends BaseMigration
{
    public function up(): void
    {
        $this->table('permissions')
            ->addColumn('role_id', 'integer', [
                'null' => false,
            ])
            ->addColumn('module', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('can_view', 'boolean', [
                'null' => false,
                'default' => false,
            ])
            ->addColumn('can_create', 'boolean', [
                'null' => false,
                'default' => false,
            ])
            ->addColumn('can_edit', 'boolean', [
                'null' => false,
                'default' => false,
            ])
            ->addColumn('can_delete', 'boolean', [
                'null' => false,
                'default' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['role_id', 'module'], ['unique' => true, 'name' => 'uq_permissions_role_module'])
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('permissions')->drop()->save();
    }
}

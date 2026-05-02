<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePipelinePermissions extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('pipeline_permissions')) {
            return;
        }

        $this->table('pipeline_permissions')
            ->addColumn('role_id', 'integer', [
                'null' => false,
                'signed' => true,
            ])
            ->addColumn('pipeline', 'string', [
                'limit' => 40,
                'null' => false,
            ])
            ->addColumn('step', 'string', [
                'limit' => 40,
                'null' => false,
            ])
            ->addColumn('can_operate', 'boolean', [
                'default' => false,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['role_id', 'pipeline', 'step'], [
                'unique' => true,
                'name' => 'pipeline_permissions_role_pipeline_step_unique',
            ])
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pipeline_permissions')) {
            $this->table('pipeline_permissions')->drop()->save();
        }
    }
}

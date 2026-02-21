<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateApprovalTokens extends BaseMigration
{
    public function up(): void
    {
        $this->table('approval_tokens')
            ->addColumn('token', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('entity_type', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('entity_id', 'integer', [
                'null' => false,
            ])
            ->addColumn('created_by', 'integer', [
                'null' => false,
            ])
            ->addColumn('expires_at', 'datetime', [
                'null' => false,
            ])
            ->addColumn('used_at', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('action_taken', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('observations', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('ip_address', 'string', [
                'limit' => 45,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('user_agent', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('created', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addIndex(['token'], ['unique' => true, 'name' => 'uq_approval_tokens_token'])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('approval_tokens')->drop()->save();
    }
}

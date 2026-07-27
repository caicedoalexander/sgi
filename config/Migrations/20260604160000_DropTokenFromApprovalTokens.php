<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Ola 5 — drop de la columna `token` en claro (ya vacía tras el backfill de la
 * Ola 3) de approval_tokens. El secreto solo vive hasheado en `token_hash`.
 */
class DropTokenFromApprovalTokens extends BaseMigration
{
    public function up(): void
    {
        $this->table('approval_tokens')
            ->removeIndexByName('uq_approval_tokens_token')
            ->removeColumn('token')
            ->update();
    }

    public function down(): void
    {
        $this->table('approval_tokens')
            ->addColumn('token', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addIndex(['token'], ['unique' => true, 'name' => 'uq_approval_tokens_token'])
            ->update();
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Ola 3 — hash en reposo de approval_tokens. Backfill irreversible: el claro se
 * pierde (down() solo retira token_hash, no restaura los secretos).
 */
class HashApprovalTokens extends BaseMigration
{
    public function up(): void
    {
        $this->table('approval_tokens')
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_approval_tokens_token_hash'])
            ->update();

        $this->execute('UPDATE approval_tokens SET token_hash = SHA2(token, 256) WHERE token IS NOT NULL');

        // token era NOT NULL: volverla nullable para poder limpiar el claro.
        $this->table('approval_tokens')
            ->changeColumn('token', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->update();

        $this->execute('UPDATE approval_tokens SET token = NULL');
    }

    public function down(): void
    {
        $this->table('approval_tokens')
            ->removeIndexByName('uq_approval_tokens_token_hash')
            ->removeColumn('token_hash')
            ->update();
    }
}

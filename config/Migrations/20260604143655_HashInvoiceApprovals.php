<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Ola 3 — hash en reposo de invoice_approvals (multi-aprobador, OBJ-1: columna
 * token_hash separada para no chocar con la mecánica token=null del N:1).
 * Backfill irreversible.
 */
class HashInvoiceApprovals extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoice_approvals')
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_invoice_approvals_token_hash'])
            ->update();

        $this->execute('UPDATE invoice_approvals SET token_hash = SHA2(token, 256) WHERE token IS NOT NULL');
        $this->execute('UPDATE invoice_approvals SET token = NULL');
    }

    public function down(): void
    {
        $this->table('invoice_approvals')
            ->removeIndexByName('uq_invoice_approvals_token_hash')
            ->removeColumn('token_hash')
            ->update();
    }
}

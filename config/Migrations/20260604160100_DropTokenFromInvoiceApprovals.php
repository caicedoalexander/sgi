<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Ola 5 — drop de la columna `token` en claro (ya vacía tras el backfill de la
 * Ola 3) de invoice_approvals. El secreto solo vive hasheado en `token_hash`.
 */
class DropTokenFromInvoiceApprovals extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoice_approvals')
            ->removeIndex(['token'])
            ->removeColumn('token')
            ->update();
    }

    public function down(): void
    {
        $this->table('invoice_approvals')
            ->addColumn('token', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addIndex(['token'], ['unique' => true])
            ->update();
    }
}

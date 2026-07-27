<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Table\InvoiceApprovalsTable;

/**
 * Test double de InvoiceApprovalsTable usado por
 * `RefundsControllerGroupSupersessionTest::testLinkInvoicesSupersedesActiveInvoiceApprovals`:
 * ignora `token_hash` en `updateAll()` porque esa columna no existe físicamente
 * en la BD de test compartida (`sgi_test`) pese a que las migraciones que la
 * agregan/consolidan están registradas como aplicadas en `cake_migrations`
 * (drift real, no introducido por esta tarea — confirmado con `DESCRIBE`
 * directo contra esa BD; la BD `default` sí tiene el esquema correcto). Delega
 * el resto a la Table real. Ver docblock de
 * `RefundsControllerGroupSupersessionTest` para el detalle completo.
 */
class InvoiceApprovalsTableWithoutTokenHashColumn extends InvoiceApprovalsTable
{
    public function updateAll($fields, $conditions): int
    {
        if (is_array($fields)) {
            unset($fields['token_hash']);
        }

        return parent::updateAll($fields, $conditions);
    }
}

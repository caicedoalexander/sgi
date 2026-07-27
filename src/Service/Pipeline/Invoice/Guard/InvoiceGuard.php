<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\Guard;

use Cake\ORM\TableRegistry;

/**
 * IO que los States puros del pipeline de facturas necesitan sobre documentos.
 * Espejo del patrón RefundApprovalGuard. NO final: PHPUnit mockea el guard.
 */
class InvoiceGuard
{
    /**
     * ≥1 fila en invoice_documents, sin importar fase ni tipo (Decisión 2 del
     * spec: la disciplina fina la pone el revisor humano).
     */
    public function hasAnyDocument(int $invoiceId): bool
    {
        return TableRegistry::getTableLocator()->get('InvoiceDocuments')
            ->exists(['invoice_id' => $invoiceId]);
    }
}

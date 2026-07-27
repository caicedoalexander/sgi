<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use Cake\ORM\TableRegistry;

/**
 * Consultas de soporte para las validaciones de avance del pipeline de
 * legalización de anticipos.
 *
 * Encapsula los accesos a BD (facturas vinculadas, soporte de relación de
 * facturas) que antes vivían inline dentro de ValidacionState, manteniendo el
 * State puro (espejo de NoveltyLiquidationGuard).
 */
class AdvanceLegalizationGuard
{
    /**
     * Facturas vinculadas al anticipo (Legalización y Recibo de Caja). Devuelve datos
     * crudos para que el State itere y decida (necesita invoice_number/id para el mensaje).
     *
     * @return list<\App\Model\Entity\Invoice>
     */
    public function linkedLegalizationInvoices(int $advanceInvoiceId): array
    {
        return TableRegistry::getTableLocator()->get('Invoices')
            ->find()
            ->where([
                'advance_id' => $advanceInvoiceId,
                'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
            ])
            ->all()
            ->toList();
    }

    /**
     * True si existe el soporte de relación de facturas (PDF) pendiente de firma.
     */
    public function hasPendingRelationDocument(int $legalizationId): bool
    {
        return TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures')
            ->exists([
                'legalization_id' => $legalizationId,
                'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
            ]);
    }
}

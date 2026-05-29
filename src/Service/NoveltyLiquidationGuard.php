<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use Cake\ORM\TableRegistry;

/**
 * Consultas de soporte para las validaciones de avance grupal de documentos de
 * liquidación de novedades.
 *
 * Encapsula los accesos a BD (documentos, firmas, miembros) que antes vivían
 * inline dentro de los States del pipeline de novedades, manteniendo los States
 * puros (espejo de cómo InvoicePaymentService se inyecta en los States de
 * Invoice que necesitan consultar pagos).
 */
final class NoveltyLiquidationGuard
{
    /**
     * True si el documento de liquidación tiene cargado el soporte de liquidación.
     */
    public function hasLiquidationDocument(int $liquidationDocId): bool
    {
        return TableRegistry::getTableLocator()->get('NoveltyDocuments')
            ->find()
            ->where([
                'liquidation_doc_id' => $liquidationDocId,
                'document_type' => NoveltyConstants::DOC_TYPE_LIQUIDATION,
            ])
            ->count() > 0;
    }

    /**
     * True si todas las firmas requeridas (no-trabajador: Contador, Coordinador)
     * están presentes.
     */
    public function requiredSignaturesComplete(int $liquidationDocId): bool
    {
        $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');

        $totalSlots = $signaturesTable->find()
            ->where([
                'liquidation_doc_id' => $liquidationDocId,
                'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
            ])
            ->count();

        $signedCount = $signaturesTable->find()
            ->where([
                'liquidation_doc_id' => $liquidationDocId,
                'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
                'signature_path IS NOT' => null,
            ])
            ->count();

        return $signedCount >= $totalSlots;
    }

    /**
     * Indica si el tipo de la primera novedad del documento requiere revisión de
     * firma del empleado. Retorna null si el documento no tiene miembros o tipo.
     */
    public function firstMemberRequiresEmployeeSignatureReview(int $liquidationDocId): ?bool
    {
        $firstMember = TableRegistry::getTableLocator()->get('EmployeeNovelties')
            ->find()
            ->contain(['NoveltyTypes'])
            ->where(['liquidation_doc_id' => $liquidationDocId])
            ->first();

        if (!$firstMember || !$firstMember->novelty_type) {
            return null;
        }

        return (bool)$firstMember->novelty_type->requires_employee_signature_review;
    }

    /**
     * True si existe un slot de firma del trabajador y aún no está firmado.
     */
    public function workerSignaturePending(int $liquidationDocId): bool
    {
        $workerSlot = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures')
            ->find()
            ->where([
                'liquidation_doc_id' => $liquidationDocId,
                'signer_type' => NoveltyConstants::SIGNER_TRABAJADOR,
            ])
            ->first();

        return $workerSlot !== null && empty($workerSlot->signature_path);
    }
}

<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño)
 * para el pipeline de reintegros.
 */
final class RefundPresentation
{
    public const STATUS_BADGES = [
        RefundConstants::STATUS_AGRUPACION        => 'pill-muted',
        RefundConstants::STATUS_CONTABILIDAD      => 'pill-primary-soft',
        RefundConstants::STATUS_TESORERIA         => 'pill-info-soft',
        RefundConstants::STATUS_AUTORIZACION_PAGO => 'pill-info-soft',
        RefundConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
        RefundConstants::STATUS_PAGADA            => 'pill-primary-soft',
    ];

    /**
     * Construye el DTO de fila para Refunds/index. Encapsula las derivaciones
     * de estado (stageIdx, pill, beneficiario, conteo) que antes vivían inline
     * en el template (Ola 2 — C2).
     */
    public static function forRow(Refund $record): RefundRowView
    {
        $status   = $record->status;
        $stageIdx = array_search($status, RefundConstants::STATUSES, true);

        return new RefundRowView(
            statusLabel:      RefundConstants::STATUS_LABELS[$status] ?? $status,
            statusBadgeClass: self::STATUS_BADGES[$status] ?? 'pill-muted',
            stageIdx:         $stageIdx === false ? -1 : $stageIdx,
            pipelineLength:   count(RefundConstants::STATUSES),
            isPaid:           $status === RefundConstants::STATUS_PAGADA,
            beneficiaryName:  $record->getBeneficiaryName() ?: null,
            invoiceCount:     count($record->invoices ?? []),
        );
    }
}

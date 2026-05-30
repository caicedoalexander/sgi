<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño)
 * para el pipeline de legalizaciones de anticipos.
 */
final class AdvancePresentation
{
    public const STATUS_BADGES = [
        AdvanceConstants::STATUS_VALIDACION        => 'pill-info-soft',
        AdvanceConstants::STATUS_REVISION_FIRMAS   => 'pill-primary-soft',
        AdvanceConstants::STATUS_CONTABILIDAD      => 'pill-warning-soft',
        AdvanceConstants::STATUS_TESORERIA         => 'pill-warning-soft',
        AdvanceConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
        AdvanceConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
        AdvanceConstants::STATUS_LEGALIZADA        => 'pill-primary-soft',
    ];

    /**
     * Construye el DTO de fila para Advances/index. El anticipo es una factura,
     * por lo que el pipeline de pago se deriva sobre InvoiceConstants /
     * InvoicePresentation; la legalización (opcional) sobre AdvanceConstants.
     * Encapsula las derivaciones que antes vivían inline en el template.
     */
    public static function forRow(Invoice $record): AdvanceRowView
    {
        $status      = $record->pipeline_status;
        $pipelineIdx = array_search($status, InvoiceConstants::PIPELINE_STATUSES, true);

        $legalization    = $record->advance_legalization ?? null;
        $legalizationIdx = $legalization
            ? array_search($legalization->status, AdvanceConstants::PIPELINE_STATUSES, true)
            : false;

        return new AdvanceRowView(
            idLabel:             $record->invoice_number ?: '#' . $record->id,
            beneficiaryName:     $record->provider->name ?? ($record->employee->full_name ?? null),
            operationCenterName: $record->hasValue('operation_center') ? $record->operation_center->name : null,
            amount:              (float)$record->amount,
            isPaid:              $status === InvoiceConstants::STATUS_PAGADA,
            pipelineIdx:         $pipelineIdx === false ? -1 : $pipelineIdx,
            pipelineLength:      count(InvoiceConstants::PIPELINE_STATUSES),
            statusLabel:         InvoiceConstants::STATUS_LABELS[$status] ?? $status,
            statusBadgeClass:    InvoicePresentation::STATUS_BADGES[$status] ?? 'pill-muted',
            hasLegalization:     $legalization !== null,
            legalizationIdx:     $legalizationIdx === false ? -1 : $legalizationIdx,
            legalizationLength:  count(AdvanceConstants::PIPELINE_STATUSES),
            legalizationLabel:   $legalization
                ? (AdvanceConstants::STATUS_LABELS[$legalization->status] ?? $legalization->status)
                : '',
            legalizationBadgeClass: $legalization
                ? (self::STATUS_BADGES[$legalization->status] ?? 'pill-muted')
                : 'pill-muted',
        );
    }
}

<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño)
 * para el pipeline de caja menor.
 */
final class PettyCashPresentation
{
    // Pills unificados vía PipelineColorMap (un color por tipo de estado en
    // todos los módulos). Test guard: PipelineColorConsistencyTest.
    public const STATUS_BADGES = [
        PettyCashConstants::STATUS_AGRUPACION        => 'pill-info-soft',
        PettyCashConstants::STATUS_CONTABILIDAD      => 'pill-orange-soft',
        PettyCashConstants::STATUS_TESORERIA         => 'pill-info-soft',
        PettyCashConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
        PettyCashConstants::STATUS_VERIFICACION_PAGO => 'pill-accent-soft',
        PettyCashConstants::STATUS_PAGADA            => 'pill-primary-soft',
    ];

    /**
     * Variante de color para .pipeline-mini según estado actual.
     */
    public static function pipelineVariant(string $status): string
    {
        return PipelineColorMap::variant($status);
    }

    /**
     * Construye el DTO de fila para PettyCashRecords/index. Encapsula las
     * derivaciones de estado (stageIdx, pill, variante de pipeline, conteo)
     * que antes vivían inline en el template.
     */
    public static function forRow(PettyCashRecord $record): PettyCashRowView
    {
        $status   = $record->status ?? '';
        $stageIdx = array_search($status, PettyCashConstants::STATUSES, true);

        return new PettyCashRowView(
            statusLabel: PettyCashConstants::STATUS_LABELS[$status] ?? $status,
            statusBadgeClass: self::STATUS_BADGES[$status] ?? 'pill-muted',
            pipelineVariant: self::pipelineVariant($status),
            stageIdx: $stageIdx === false ? -1 : $stageIdx,
            pipelineLength: count(PettyCashConstants::STATUSES),
            isPaid: $status === PettyCashConstants::STATUS_PAGADA,
            invoiceCount: count($record->invoices ?? []),
        );
    }
}

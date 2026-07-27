<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño)
 * para el pipeline de programación de pagos.
 */
final class PaymentSchedulingPresentation
{
    // Pills unificados vía PipelineColorMap (un color por tipo de estado en
    // todos los módulos). Test guard: PipelineColorConsistencyTest.
    public const STATUS_BADGES = [
        PaymentSchedulingConstants::STATUS_BORRADOR          => 'pill-muted',
        PaymentSchedulingConstants::STATUS_TESORERIA         => 'pill-info-soft',
        PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
        PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO => 'pill-accent-soft',
        PaymentSchedulingConstants::STATUS_PAGADA            => 'pill-primary-soft',
    ];

    /** Variante de color para .pipeline-mini según estado actual. */
    public static function pipelineVariant(string $status): string
    {
        return PipelineColorMap::variant($status);
    }

    /**
     * Construye el DTO de fila para PaymentSchedulings/index. Encapsula las
     * derivaciones de estado (stageIdx, pill, label, conteo) que antes vivían
     * inline en el template.
     */
    public static function forRow(PaymentScheduling $record): PaymentSchedulingRowView
    {
        $status   = $record->pipeline_status;
        $steps    = PaymentSchedulingConstants::PIPELINE_STATUSES;
        $stageIdx = array_search($status, $steps, true);
        $labels   = PaymentSchedulingConstants::STATUS_LABELS;

        return new PaymentSchedulingRowView(
            statusLabel: $labels[$status] ?? $status,
            statusBadgeClass: self::STATUS_BADGES[$status] ?? 'pill-muted',
            pipelineVariant: self::pipelineVariant($status),
            stageIdx: $stageIdx === false ? -1 : $stageIdx,
            pipelineLength: count($steps),
            isPaid: $status === PaymentSchedulingConstants::STATUS_PAGADA,
            itemCount: count($record->payment_scheduling_items ?? []),
        );
    }
}

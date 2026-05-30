<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\PaymentSchedulingConstants;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño)
 * para el pipeline de programación de pagos.
 */
final class PaymentSchedulingPresentation
{
    public const STATUS_BADGES = [
        PaymentSchedulingConstants::STATUS_BORRADOR          => 'pill-muted',
        PaymentSchedulingConstants::STATUS_TESORERIA         => 'pill-info-soft',
        PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO => 'pill-info-soft',
        PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
        PaymentSchedulingConstants::STATUS_PAGADA            => 'pill-primary-soft',
    ];

    /**
     * Construye el DTO de fila para PaymentSchedulings/index. Encapsula las
     * derivaciones de estado (stageIdx, pill, label, conteo) que antes vivían
     * inline en el template.
     */
    public static function forRow(\App\Model\Entity\PaymentScheduling $record): PaymentSchedulingRowView
    {
        $status   = $record->pipeline_status;
        $steps    = PaymentSchedulingConstants::PIPELINE_STATUSES;
        $stageIdx = array_search($status, $steps, true);
        $labels   = PaymentSchedulingConstants::STATUS_LABELS;

        return new PaymentSchedulingRowView(
            statusLabel:      $labels[$status] ?? $status,
            statusBadgeClass: self::STATUS_BADGES[$status] ?? 'pill-muted',
            stageIdx:         $stageIdx === false ? -1 : $stageIdx,
            pipelineLength:   count($steps),
            isPaid:           $status === PaymentSchedulingConstants::STATUS_PAGADA,
            itemCount:        count($record->payment_scheduling_items ?? []),
        );
    }
}

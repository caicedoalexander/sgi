<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable con los datos de presentación de una fila del listado de
 * programaciones de pago (PaymentSchedulings/index). Producido por
 * PaymentSchedulingPresentation::forRow().
 */
final readonly class PaymentSchedulingRowView
{
    public function __construct(
        public string $statusLabel,
        public string $statusBadgeClass,
        public int    $stageIdx,
        public int    $pipelineLength,
        public bool   $isPaid,
        public int    $itemCount,
    ) {
    }
}

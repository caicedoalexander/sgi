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
    /**
     * @param string $statusLabel Etiqueta ES del estado.
     * @param string $statusBadgeClass Clase de badge del estado.
     * @param string $pipelineVariant Variante visual del pipeline.
     * @param int $stageIdx Índice del paso actual en el pipeline.
     * @param int $pipelineLength Cantidad de pasos del pipeline.
     * @param bool $isPaid La programación está pagada.
     * @param int $itemCount Número de ítems de la programación.
     */
    public function __construct(
        public string $statusLabel,
        public string $statusBadgeClass,
        public string $pipelineVariant,
        public int $stageIdx,
        public int $pipelineLength,
        public bool $isPaid,
        public int $itemCount,
    ) {
    }
}

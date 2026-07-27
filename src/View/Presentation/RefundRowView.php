<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable con los datos de presentación de una fila del listado de
 * reintegros (Refunds/index). Producido por RefundPresentation::forRow().
 */
final readonly class RefundRowView
{
    /**
     * @param string $statusLabel Etiqueta ES del estado.
     * @param string $statusBadgeClass Clase de badge del estado.
     * @param string $pipelineVariant Variante visual del pipeline.
     * @param int $stageIdx Índice del paso actual en el pipeline.
     * @param int $pipelineLength Cantidad de pasos del pipeline.
     * @param bool $isPaid El reintegro está pagado.
     * @param string|null $beneficiaryName Nombre del beneficiario.
     * @param int $invoiceCount Número de facturas vinculadas.
     */
    public function __construct(
        public string $statusLabel,
        public string $statusBadgeClass,
        public string $pipelineVariant,
        public int $stageIdx,
        public int $pipelineLength,
        public bool $isPaid,
        public ?string $beneficiaryName,
        public int $invoiceCount,
    ) {
    }
}

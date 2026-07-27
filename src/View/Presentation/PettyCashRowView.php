<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable con los datos de presentación de una fila del listado de
 * caja menor (PettyCashRecords/index). Producido por
 * PettyCashPresentation::forRow().
 */
final readonly class PettyCashRowView
{
    /**
     * @param string $statusLabel Etiqueta ES del estado.
     * @param string $statusBadgeClass Clase de badge del estado.
     * @param string $pipelineVariant Variante visual del pipeline.
     * @param int $stageIdx Índice del paso actual en el pipeline.
     * @param int $pipelineLength Cantidad de pasos del pipeline.
     * @param bool $isPaid La caja menor está pagada.
     * @param int $invoiceCount Número de facturas vinculadas.
     */
    public function __construct(
        public string $statusLabel,
        public string $statusBadgeClass,
        public string $pipelineVariant,
        public int $stageIdx,
        public int $pipelineLength,
        public bool $isPaid,
        public int $invoiceCount,
    ) {
    }
}

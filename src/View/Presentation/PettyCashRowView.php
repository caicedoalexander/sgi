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
    public function __construct(
        public string $statusLabel,
        public string $statusBadgeClass,
        public string $pipelineVariant,
        public int    $stageIdx,
        public int    $pipelineLength,
        public bool   $isPaid,
        public int    $invoiceCount,
    ) {
    }
}

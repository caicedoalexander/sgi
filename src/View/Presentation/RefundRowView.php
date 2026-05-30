<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable con los datos de presentación de una fila del listado de
 * reintegros (Refunds/index). Producido por RefundPresentation::forRow().
 */
final readonly class RefundRowView
{
    public function __construct(
        public string  $statusLabel,
        public string  $statusBadgeClass,
        public int     $stageIdx,
        public int     $pipelineLength,
        public bool    $isPaid,
        public ?string $beneficiaryName,
        public int     $invoiceCount,
    ) {
    }
}

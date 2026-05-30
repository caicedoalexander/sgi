<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable con los datos de presentación de una fila del listado de
 * anticipos (Advances/index). Producido por AdvancePresentation::forRow().
 *
 * Cada fila tiene DOS pipelines: el de pago (factura) y el de legalización
 * (opcional, sólo cuando ya hay legalización iniciada).
 */
final readonly class AdvanceRowView
{
    public function __construct(
        // Pipeline de pago (factura).
        public string  $idLabel,
        public ?string $beneficiaryName,
        public ?string $operationCenterName,
        public float   $amount,
        public bool    $isPaid,
        public int     $pipelineIdx,
        public int     $pipelineLength,
        public string  $statusLabel,
        public string  $statusBadgeClass,
        // Pipeline de legalización (null si no hay legalización iniciada).
        public bool    $hasLegalization,
        public int     $legalizationIdx,
        public int     $legalizationLength,
        public string  $legalizationLabel,
        public string  $legalizationBadgeClass,
    ) {
    }
}

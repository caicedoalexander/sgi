<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable con la derivación de estado de una fila del listado de
 * documentos de liquidación (NoveltyLiquidationDocs/index). Producido por
 * NoveltyPresentation::forLiquidationDocRow().
 */
final readonly class NoveltyLiquidationDocRowView
{
    public function __construct(
        public string $statusLabel,
        public string $statusBadgeClass,
        public string $periodLabel,
        public int    $noveltyCount,
    ) {
    }
}

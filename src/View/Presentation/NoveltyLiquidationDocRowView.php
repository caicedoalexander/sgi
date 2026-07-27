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
    /**
     * @param string $statusLabel Etiqueta ES del estado.
     * @param string $statusBadgeClass Clase de badge del estado.
     * @param string $periodLabel Etiqueta del período de liquidación.
     * @param int $noveltyCount Número de novedades agrupadas.
     */
    public function __construct(
        public string $statusLabel,
        public string $statusBadgeClass,
        public string $periodLabel,
        public int $noveltyCount,
    ) {
    }
}

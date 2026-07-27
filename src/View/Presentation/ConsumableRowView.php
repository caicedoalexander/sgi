<?php
declare(strict_types=1);

namespace App\View\Presentation;

final readonly class ConsumableRowView
{
    /**
     * @param string $stockLabel Etiqueta de estado de stock.
     * @param string $stockBadgeClass Clase pill del Sistema de Diseño.
     * @param string $operationCenterName Nombre de la sede asociada.
     */
    public function __construct(
        public string $stockLabel,
        public string $stockBadgeClass,
        public string $operationCenterName,
    ) {
    }
}

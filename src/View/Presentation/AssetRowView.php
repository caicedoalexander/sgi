<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO de fila para Assets/index. Producido por AssetPresentation::forRow().
 * Encapsula las derivaciones de estado/etiquetas/asociaciones que de otro modo
 * vivirían inline en el template.
 */
final readonly class AssetRowView
{
    /**
     * @param string $statusLabel Label legible del estado del activo.
     * @param string $statusBadgeClass Clase pill del Sistema de Diseño.
     * @param string $statusIcon Icono Bootstrap Icons para el estado.
     * @param string $categoryName Nombre de la categoría del activo.
     * @param string $responsibleName Nombre completo del responsable, o '—'.
     * @param string $operationCenterName Nombre del centro de operación, o '—'.
     */
    public function __construct(
        public string $statusLabel,
        public string $statusBadgeClass,
        public string $statusIcon,
        public string $categoryName,
        public string $responsibleName,
        public string $operationCenterName,
    ) {
    }
}

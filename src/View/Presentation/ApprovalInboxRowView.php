<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable de una fila del listado Approvals/index. Producido por
 * ApprovalInboxPresentation::forRow(). Toda la derivación de presentación de
 * fila vive aquí; el template no deriva nada inline.
 */
final readonly class ApprovalInboxRowView
{
    /**
     * @param string $module Módulo de origen de la fila.
     * @param int $entityId ID de la entidad aprobable.
     * @param string $code Código legible de la entidad.
     * @param string $counterparty Contraparte (proveedor/empleado).
     * @param string $summary Resumen de la entidad.
     * @param string $status Estado de aprobación.
     * @param string $statusLabel Etiqueta ES del estado.
     * @param string $statusBadgeClass Clase de badge del estado.
     * @param string $moduleLabel Etiqueta ES del módulo.
     * @param string $moduleBadgeClass Clase de badge del módulo.
     * @param string $assignedAtLabel Fecha de asignación formateada.
     */
    public function __construct(
        public string $module,
        public int $entityId,
        public string $code,
        public string $counterparty,
        public string $summary,
        public string $status,
        public string $statusLabel,
        public string $statusBadgeClass,
        public string $moduleLabel,
        public string $moduleBadgeClass,
        public string $assignedAtLabel,
    ) {
    }
}

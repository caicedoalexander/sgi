<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable de una fila de Pending/index. Producido por
 * PendingPresentation::forRow(). Toda la derivación de presentación vive ahí;
 * el template no deriva nada inline. `pipelineSteps` vacío ⇒ sin pipeline-mini.
 */
final readonly class PendingRowView
{
    /**
     * @param string $module Slug interno del módulo.
     * @param string $moduleLabel Etiqueta ES del módulo.
     * @param string $moduleBadgeClass Clase pill del módulo.
     * @param int $entityId Id de la entidad destino.
     * @param string $code Código legible.
     * @param string $counterparty Contraparte.
     * @param string $summary Resumen.
     * @param string $statusLabel Etiqueta ES del estado.
     * @param string $pillClass Clase pill del estado.
     * @param array<int,string> $pipelineSteps Pasos ordenados del pipeline (vacío = pill-only).
     * @param int $stageIdx Índice del estado actual en los pasos (-1 si no aplica).
     * @param string $pipelineVariant Variante de color de la pipeline-mini.
     * @param string $dateLabel Fecha formateada d/m/Y.
     * @param array $route URL array de CakePHP al detalle del módulo.
     */
    public function __construct(
        public string $module,
        public string $moduleLabel,
        public string $moduleBadgeClass,
        public int $entityId,
        public string $code,
        public string $counterparty,
        public string $summary,
        public string $statusLabel,
        public string $pillClass,
        public array $pipelineSteps,
        public int $stageIdx,
        public string $pipelineVariant,
        public string $dateLabel,
        public array $route,
    ) {
    }
}

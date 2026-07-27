<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Service\Dto\PendingItem;

/**
 * Diccionario de presentación de la bandeja Mis Pendientes. Único punto de
 * derivación de fila: pill+label de módulo, pipeline-mini (solo módulos `mini`),
 * pill+label de estado, ruta. Anti-drift: consume PendingModuleMeta, no redeclara
 * mapas.
 */
final class PendingPresentation
{
    /**
     * Construye el DTO de fila para Pending/index.
     */
    public static function forRow(PendingItem $item): PendingRowView
    {
        $meta = PendingModuleMeta::MODULES[$item->module] ?? [];
        $mini = (bool)($meta['mini'] ?? false);
        $steps = $mini ? ($meta['steps'] ?? []) : [];

        $stageIdx = -1;
        if ($steps !== []) {
            $found = array_search($item->status, $steps, true);
            $stageIdx = $found === false ? -1 : (int)$found;
        }

        $statusLabels = $meta['statusLabels'] ?? [];
        $pills = $meta['pills'] ?? [];

        return new PendingRowView(
            module: $item->module,
            moduleLabel: (string)($meta['label'] ?? $item->module),
            moduleBadgeClass: (string)($meta['moduleBadge'] ?? 'pill-muted'),
            entityId: $item->entityId,
            code: $item->code,
            counterparty: $item->counterparty,
            summary: $item->summary,
            statusLabel: (string)($statusLabels[$item->status] ?? $item->status),
            pillClass: (string)($pills[$item->status] ?? 'pill-muted'),
            pipelineSteps: $steps,
            stageIdx: $stageIdx,
            pipelineVariant: $mini ? PipelineColorMap::variant($item->status) : '',
            dateLabel: $item->date->format('d/m/Y'),
            route: [
                'controller' => (string)($meta['controller'] ?? 'Dashboard'),
                'action' => (string)($meta['action'] ?? 'index'),
                $item->entityId,
            ],
        );
    }
}

<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\ApprovalInboxConstants;
use App\Service\Dto\ApprovalInboxItem;

/**
 * Diccionario de presentación de la bandeja de aprobaciones: mapeo estado→pill,
 * estado→icono y módulo→pill. Fuente única anti-drift; los templates NO
 * redeclaran estos mapas.
 */
final class ApprovalInboxPresentation
{
    public const STATUS_BADGES = [
        ApprovalInboxConstants::STATUS_PENDING  => 'pill-warning-soft',
        ApprovalInboxConstants::STATUS_APPROVED => 'pill-primary-soft',
        ApprovalInboxConstants::STATUS_REJECTED => 'pill-danger-soft',
    ];

    public const STATUS_ICONS = [
        ApprovalInboxConstants::STATUS_PENDING  => 'bi-hourglass-split',
        ApprovalInboxConstants::STATUS_APPROVED => 'bi-check-circle',
        ApprovalInboxConstants::STATUS_REJECTED => 'bi-x-circle',
    ];

    public const MODULE_BADGES = [
        ApprovalInboxConstants::MODULE_INVOICE => 'pill-info-soft',
        ApprovalInboxConstants::MODULE_NOVELTY => 'pill-muted',
    ];

    /**
     * Construye el DTO de fila para Approvals/index. Único punto de derivación.
     */
    public static function forRow(ApprovalInboxItem $item): ApprovalInboxRowView
    {
        return new ApprovalInboxRowView(
            module: $item->module,
            entityId: $item->entityId,
            code: $item->code,
            counterparty: $item->counterparty,
            summary: $item->summary,
            status: $item->status,
            statusLabel: ApprovalInboxConstants::STATUS_LABELS[$item->status] ?? $item->status,
            statusBadgeClass: self::STATUS_BADGES[$item->status] ?? 'pill-muted',
            moduleLabel: ApprovalInboxConstants::MODULE_LABELS[$item->module] ?? $item->module,
            moduleBadgeClass: self::MODULE_BADGES[$item->module] ?? 'pill-muted',
            assignedAtLabel: $item->assignedAt->format('d/m/Y'),
        );
    }
}

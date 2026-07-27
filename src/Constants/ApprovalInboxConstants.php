<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Slugs internos (en inglés, no visibles al usuario) de la bandeja de
 * aprobaciones, y el mapeo desde los estados persistidos en español.
 *
 * Los valores persistidos viven en español en InvoiceConstants::APPROVER_STATUS_*
 * y NoveltyConstants::APPROVAL_*; aquí se normalizan a un slug común para el DTO.
 */
final class ApprovalInboxConstants
{
    public const MODULE_INVOICE = 'invoice';
    public const MODULE_NOVELTY = 'novelty';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** Estados seleccionables en los chips del index. */
    public const FILTERABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    /** Mapeo de los valores persistidos (español) → slug interno. */
    public const STATUS_MAP = [
        'Pendiente' => self::STATUS_PENDING,
        'Aprobada' => self::STATUS_APPROVED,
        'Rechazada' => self::STATUS_REJECTED,
    ];

    /** Etiquetas visibles (español) por slug. */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_APPROVED => 'Aprobada',
        self::STATUS_REJECTED => 'Rechazada',
    ];

    public const MODULE_LABELS = [
        self::MODULE_INVOICE => 'Factura',
        self::MODULE_NOVELTY => 'Novedad',
    ];
}

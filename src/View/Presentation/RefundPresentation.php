<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\RefundConstants;

/**
 * Configuración de presentación (badges Bootstrap, iconos) para el pipeline
 * de reintegros.
 */
final class RefundPresentation
{
    public const STATUS_BADGES = [
        RefundConstants::STATUS_AGRUPACION        => 'bg-secondary',
        RefundConstants::STATUS_CONTABILIDAD      => 'bg-primary',
        RefundConstants::STATUS_TESORERIA         => 'bg-info',
        RefundConstants::STATUS_AUTORIZACION_PAGO => 'bg-info',
        RefundConstants::STATUS_VERIFICACION_PAGO => 'bg-warning text-dark',
        RefundConstants::STATUS_PAGADA            => 'bg-success',
    ];

    /**
     * Badges del header del formulario de edición. Distintos a STATUS_BADGES
     * (énfasis visual del contexto edit). NO consolidar — audit CR-203.
     */
    public const EDIT_HEADER_BADGES = [
        RefundConstants::STATUS_AGRUPACION        => 'bg-info text-dark',
        RefundConstants::STATUS_CONTABILIDAD      => 'bg-primary',
        RefundConstants::STATUS_TESORERIA         => 'bg-warning text-dark',
        RefundConstants::STATUS_AUTORIZACION_PAGO => 'bg-secondary',
        RefundConstants::STATUS_PAGADA            => 'bg-success',
    ];

    public const STATUS_ICONS = [
        RefundConstants::STATUS_AGRUPACION        => 'bi-collection',
        RefundConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        RefundConstants::STATUS_TESORERIA         => 'bi-bank',
        RefundConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        RefundConstants::STATUS_VERIFICACION_PAGO => 'bi-hourglass-split',
        RefundConstants::STATUS_PAGADA            => 'bi-cash-coin',
    ];
}

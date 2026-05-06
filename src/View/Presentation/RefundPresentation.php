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
        RefundConstants::STATUS_PAGADA            => 'bg-success',
    ];

    public const STATUS_ICONS = [
        RefundConstants::STATUS_AGRUPACION        => 'bi-collection',
        RefundConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        RefundConstants::STATUS_TESORERIA         => 'bi-bank',
        RefundConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        RefundConstants::STATUS_PAGADA            => 'bi-cash-coin',
    ];
}

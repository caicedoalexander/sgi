<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\RefundConstants;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño, iconos)
 * para el pipeline de reintegros.
 */
final class RefundPresentation
{
    public const STATUS_BADGES = [
        RefundConstants::STATUS_AGRUPACION        => 'pill-muted',
        RefundConstants::STATUS_CONTABILIDAD      => 'pill-primary-soft',
        RefundConstants::STATUS_TESORERIA         => 'pill-info-soft',
        RefundConstants::STATUS_AUTORIZACION_PAGO => 'pill-info-soft',
        RefundConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
        RefundConstants::STATUS_PAGADA            => 'pill-primary-soft',
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

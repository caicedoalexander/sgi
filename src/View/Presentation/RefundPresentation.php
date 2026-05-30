<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\RefundConstants;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño)
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
}

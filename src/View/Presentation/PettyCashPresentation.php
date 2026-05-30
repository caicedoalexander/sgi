<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\PettyCashConstants;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño)
 * para el pipeline de caja menor.
 */
final class PettyCashPresentation
{
    public const STATUS_BADGES = [
        PettyCashConstants::STATUS_AGRUPACION        => 'pill-info-soft',
        PettyCashConstants::STATUS_CONTABILIDAD      => 'pill-primary-soft',
        PettyCashConstants::STATUS_TESORERIA         => 'pill-warning-soft',
        PettyCashConstants::STATUS_AUTORIZACION_PAGO => 'pill-info-soft',
        PettyCashConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
        PettyCashConstants::STATUS_PAGADA            => 'pill-primary-soft',
    ];
}

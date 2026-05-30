<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\AdvanceConstants;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño)
 * para el pipeline de legalizaciones de anticipos.
 */
final class AdvancePresentation
{
    public const STATUS_BADGES = [
        AdvanceConstants::STATUS_VALIDACION        => 'pill-info-soft',
        AdvanceConstants::STATUS_REVISION_FIRMAS   => 'pill-primary-soft',
        AdvanceConstants::STATUS_CONTABILIDAD      => 'pill-warning-soft',
        AdvanceConstants::STATUS_TESORERIA         => 'pill-warning-soft',
        AdvanceConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
        AdvanceConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
        AdvanceConstants::STATUS_LEGALIZADA        => 'pill-primary-soft',
    ];
}

<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\AdvanceConstants;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño, iconos)
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

    public const STATUS_ICONS = [
        AdvanceConstants::STATUS_VALIDACION        => 'bi-clipboard-check',
        AdvanceConstants::STATUS_REVISION_FIRMAS   => 'bi-pen',
        AdvanceConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        AdvanceConstants::STATUS_TESORERIA         => 'bi-bank',
        AdvanceConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        AdvanceConstants::STATUS_VERIFICACION_PAGO => 'bi-hourglass-split',
        AdvanceConstants::STATUS_LEGALIZADA        => 'bi-cash-coin',
    ];
}

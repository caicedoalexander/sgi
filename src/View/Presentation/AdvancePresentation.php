<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\AdvanceConstants;

/**
 * Configuración de presentación (badges Bootstrap, iconos) para el pipeline
 * de legalizaciones de anticipos.
 */
final class AdvancePresentation
{
    public const STATUS_BADGES = [
        AdvanceConstants::STATUS_VALIDACION        => 'bg-info text-dark',
        AdvanceConstants::STATUS_REVISION_FIRMAS   => 'bg-primary',
        AdvanceConstants::STATUS_CONTABILIDAD      => 'bg-warning text-dark',
        AdvanceConstants::STATUS_TESORERIA         => 'bg-warning text-dark',
        AdvanceConstants::STATUS_AUTORIZACION_PAGO => 'bg-warning text-dark',
        AdvanceConstants::STATUS_VERIFICACION_PAGO => 'bg-warning text-dark',
        AdvanceConstants::STATUS_LEGALIZADA        => 'bg-success',
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

<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\PettyCashConstants;

/**
 * Configuración de presentación (badges Bootstrap, iconos) para el pipeline
 * de caja menor.
 */
final class PettyCashPresentation
{
    public const STATUS_BADGES = [
        PettyCashConstants::STATUS_AGRUPACION        => 'bg-info text-dark',
        PettyCashConstants::STATUS_CONTABILIDAD      => 'bg-primary',
        PettyCashConstants::STATUS_TESORERIA         => 'bg-warning text-dark',
        PettyCashConstants::STATUS_AUTORIZACION_PAGO => 'bg-info',
        PettyCashConstants::STATUS_VERIFICACION_PAGO => 'bg-warning text-dark',
        PettyCashConstants::STATUS_PAGADA            => 'bg-success',
    ];

    /**
     * Badges del header del formulario de edición. Distintos a STATUS_BADGES
     * (énfasis visual del contexto edit). NO consolidar — audit CR-203.
     */
    public const EDIT_HEADER_BADGES = [
        PettyCashConstants::STATUS_AGRUPACION        => 'bg-info text-dark',
        PettyCashConstants::STATUS_CONTABILIDAD      => 'bg-primary',
        PettyCashConstants::STATUS_TESORERIA         => 'bg-warning text-dark',
        PettyCashConstants::STATUS_AUTORIZACION_PAGO => 'bg-info text-dark',
        PettyCashConstants::STATUS_PAGADA            => 'bg-success',
    ];

    public const STATUS_ICONS = [
        PettyCashConstants::STATUS_AGRUPACION        => 'bi-collection',
        PettyCashConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        PettyCashConstants::STATUS_TESORERIA         => 'bi-bank',
        PettyCashConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        PettyCashConstants::STATUS_VERIFICACION_PAGO => 'bi-hourglass-split',
        PettyCashConstants::STATUS_PAGADA            => 'bi-cash-coin',
    ];
}

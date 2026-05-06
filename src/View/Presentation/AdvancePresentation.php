<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\AdvanceConstants;

/**
 * Configuración de presentación (iconos) para el pipeline de legalizaciones
 * de anticipos. Los badges del listado se definen inline en
 * `templates/Advances/{index,view}.php` por divergencia visual deliberada.
 */
final class AdvancePresentation
{
    public const STATUS_ICONS = [
        AdvanceConstants::STATUS_VALIDACION        => 'bi-clipboard-check',
        AdvanceConstants::STATUS_REVISION_FIRMAS   => 'bi-pen',
        AdvanceConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        AdvanceConstants::STATUS_TESORERIA         => 'bi-bank',
        AdvanceConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        AdvanceConstants::STATUS_LEGALIZADA        => 'bi-cash-coin',
    ];
}

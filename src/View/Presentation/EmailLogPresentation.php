<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\EmailLogConstants;

/**
 * Configuración de presentación (clases pill + iconos del Sistema de Diseño)
 * para el log de envío de correos. Datos puros de UI.
 */
final class EmailLogPresentation
{
    public const STATUS_BADGES = [
        EmailLogConstants::STATUS_SENT    => 'pill-primary-soft',
        EmailLogConstants::STATUS_FAILED  => 'pill-danger-soft',
        EmailLogConstants::STATUS_PENDING => 'pill-warning-soft',
    ];

    public const STATUS_ICONS = [
        EmailLogConstants::STATUS_SENT    => 'bi-check-circle',
        EmailLogConstants::STATUS_FAILED  => 'bi-x-circle',
        EmailLogConstants::STATUS_PENDING => 'bi-hourglass-split',
    ];

    public const DEFAULT_BADGE = 'pill-secondary-soft';
    public const DEFAULT_ICON = 'bi-question-circle';
}

<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño)
 * para el cruce DIAN. Datos puros de UI.
 */
final class DianCrosscheckPresentation
{
    public const STATUS_BADGES = [
        'enviado'    => 'pill-info-soft',
        'procesando' => 'pill-warning-soft',
        'completado' => 'pill-primary-soft',
        'error'      => 'pill-danger-soft',
    ];

    public const DEFAULT_BADGE = 'pill-muted';
}

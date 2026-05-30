<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\NoveltyConstants;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño,
 * colores de calendario) para el pipeline de novedades. Datos puros de UI —
 * no contiene reglas de dominio.
 */
final class NoveltyPresentation
{
    public const STATUS_BADGES = [
        NoveltyConstants::STATUS_REGISTRO         => 'pill-muted',
        NoveltyConstants::STATUS_APROBACION       => 'pill-warning-soft',
        NoveltyConstants::STATUS_RRHH             => 'pill-accent-soft',
        NoveltyConstants::STATUS_CONTABILIDAD     => 'pill-primary-soft',
        NoveltyConstants::STATUS_REVISION_FIRMAS  => 'pill-warning-soft',
        NoveltyConstants::STATUS_GDP              => 'pill-muted',
        NoveltyConstants::STATUS_TESORERIA        => 'pill-info-soft',
        NoveltyConstants::STATUS_AUTORIZACION_PAGO => 'pill-info-soft',
        NoveltyConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
        NoveltyConstants::STATUS_PAGADA           => 'pill-primary-soft',
        NoveltyConstants::STATUS_RECHAZADA        => 'pill-danger-soft',
    ];

    /** Mapping para el campo area_approval. Default no listado: 'pill-warning-soft'. */
    public const APPROVAL_BADGES = [
        NoveltyConstants::APPROVAL_APPROVED => 'pill-primary-soft',
        NoveltyConstants::APPROVAL_REJECTED => 'pill-danger-soft',
    ];

    /**
     * Paleta de colores para eventos del calendario de novedades, indexada por
     * ID del tipo de novedad (cycles modulo count cuando hay más tipos).
     */
    public const CALENDAR_COLORS = [
        '#469D61', // green
        '#CD6A15', // orange
        '#3B82F6', // blue
        '#8B5CF6', // purple
        '#EF4444', // red
        '#F59E0B', // amber
        '#06B6D4', // cyan
        '#EC4899', // pink
        '#10B981', // emerald
        '#6366F1', // indigo
    ];
}

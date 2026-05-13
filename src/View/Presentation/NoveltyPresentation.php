<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\NoveltyConstants;

/**
 * Configuración de presentación (badges Bootstrap, iconos, colores de calendario)
 * para el pipeline de novedades. Datos puros de UI — no contiene reglas de dominio.
 */
final class NoveltyPresentation
{
    public const STATUS_BADGES = [
        NoveltyConstants::STATUS_REGISTRO         => 'bg-light text-dark',
        NoveltyConstants::STATUS_APROBACION       => 'bg-warning text-dark',
        NoveltyConstants::STATUS_RRHH             => 'bg-purple',
        NoveltyConstants::STATUS_CONTABILIDAD     => 'bg-primary',
        NoveltyConstants::STATUS_REVISION_FIRMAS  => 'bg-warning text-dark',
        NoveltyConstants::STATUS_GDP              => 'bg-dark',
        NoveltyConstants::STATUS_TESORERIA        => 'bg-info',
        NoveltyConstants::STATUS_AUTORIZACION_PAGO => 'bg-info',
        NoveltyConstants::STATUS_VERIFICACION_PAGO => 'bg-warning text-dark',
        NoveltyConstants::STATUS_PAGADA           => 'bg-success',
        NoveltyConstants::STATUS_RECHAZADA        => 'bg-danger',
    ];

    public const STATUS_ICONS = [
        NoveltyConstants::STATUS_REGISTRO          => 'bi-pencil-square',
        NoveltyConstants::STATUS_APROBACION        => 'bi-person-check',
        NoveltyConstants::STATUS_RRHH              => 'bi-people',
        NoveltyConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        NoveltyConstants::STATUS_REVISION_FIRMAS   => 'bi-pen',
        NoveltyConstants::STATUS_GDP               => 'bi-clipboard-check',
        NoveltyConstants::STATUS_TESORERIA         => 'bi-bank',
        NoveltyConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        NoveltyConstants::STATUS_VERIFICACION_PAGO => 'bi-hourglass-split',
        NoveltyConstants::STATUS_PAGADA            => 'bi-cash-coin',
    ];

    /** Mapping para el campo area_approval. Default no listado: 'bg-warning text-dark'. */
    public const APPROVAL_BADGES = [
        NoveltyConstants::APPROVAL_APPROVED => 'bg-success',
        NoveltyConstants::APPROVAL_REJECTED => 'bg-danger',
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

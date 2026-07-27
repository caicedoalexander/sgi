<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño,
 * colores de calendario) para el pipeline de novedades. Datos puros de UI —
 * no contiene reglas de dominio.
 */
final class NoveltyPresentation
{
    // Pills unificados vía PipelineColorMap (un color por tipo de estado en
    // todos los módulos). Test guard: PipelineColorConsistencyTest.
    public const STATUS_BADGES = [
        NoveltyConstants::STATUS_REGISTRO         => 'pill-muted',
        NoveltyConstants::STATUS_APROBACION       => 'pill-warning-soft',
        NoveltyConstants::STATUS_RRHH             => 'pill-accent-soft',
        NoveltyConstants::STATUS_CONTABILIDAD     => 'pill-orange-soft',
        NoveltyConstants::STATUS_REVISION_FIRMAS  => 'pill-info-soft',
        NoveltyConstants::STATUS_GDP              => 'pill-muted',
        NoveltyConstants::STATUS_TESORERIA        => 'pill-info-soft',
        NoveltyConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
        NoveltyConstants::STATUS_VERIFICACION_PAGO => 'pill-accent-soft',
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

    /**
     * Derivación de estado para una fila de EmployeeNovelties/index (vista lista).
     * Encapsula isRejected/isPaid + pill/label que antes vivían inline en el loop.
     */
    public static function forEmployeeNoveltyRow(EmployeeNovelty $record): EmployeeNoveltyRowView
    {
        $status     = (string)$record->pipeline_status;
        $isRejected = $record->isRejected();

        return new EmployeeNoveltyRowView(
            isRejected: $isRejected,
            isPaid: $status === NoveltyConstants::STATUS_PAGADA,
            statusLabel: NoveltyConstants::STATUS_LABELS[$status] ?? $status,
            statusBadgeClass: $isRejected
                ? 'pill-danger-soft'
                : (self::STATUS_BADGES[$status] ?? 'pill-muted'),
        );
    }

    /**
     * Derivación de estado para una fila de NoveltyLiquidationDocs/index.
     */
    public static function forLiquidationDocRow(NoveltyLiquidationDoc $record): NoveltyLiquidationDocRowView
    {
        $status = (string)$record->pipeline_status;

        return new NoveltyLiquidationDocRowView(
            statusLabel: NoveltyConstants::STATUS_LABELS[$status] ?? $status,
            statusBadgeClass: self::STATUS_BADGES[$status] ?? 'pill-muted',
            periodLabel: NoveltyConstants::PERIOD_LABELS[$record->period] ?? (string)$record->period,
            noveltyCount: count($record->employee_novelties ?? []),
        );
    }
}

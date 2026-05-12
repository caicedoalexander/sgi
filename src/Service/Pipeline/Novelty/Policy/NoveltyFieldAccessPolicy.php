<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\Policy;

use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Service\Pipeline\PipelineFieldPolicy;

/**
 * Campos editables y secciones visibles por estado del pipeline de novedades.
 *
 * Audit PA-008 — extraído de `NoveltyService::FIELDS_BY_STEP/SECTIONS_BY_STEP`
 * para unificar con el resto de dominios bajo `PipelineFieldPolicy`.
 */
final class NoveltyFieldAccessPolicy extends PipelineFieldPolicy
{
    private const FIELDS_BY_STEP = [
        NoveltyConstants::STATUS_APROBACION => ['approver_id'],
        NoveltyConstants::STATUS_RRHH => ['passes_payroll'],
        NoveltyConstants::STATUS_CONTABILIDAD => ['liquidation_doc_id'],
    ];

    private const SECTIONS_BY_STEP = [
        NoveltyConstants::STATUS_APROBACION => ['informacion', 'fechas', 'motivo', 'aprobacion', 'firmas'],
        NoveltyConstants::STATUS_RRHH => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'firmas'],
        NoveltyConstants::STATUS_CONTABILIDAD => ['informacion', 'fechas', 'contabilidad'],
        NoveltyConstants::STATUS_REVISION_FIRMAS => ['informacion', 'fechas', 'firmas'],
        NoveltyConstants::STATUS_GDP => ['informacion', 'fechas', 'firmas'],
        NoveltyConstants::STATUS_TESORERIA => ['informacion'],
        NoveltyConstants::STATUS_AUTORIZACION_PAGO => ['informacion'],
    ];

    /**
     * @return array<string, array<int, string>>
     */
    protected static function fieldsByStep(): array
    {
        return self::FIELDS_BY_STEP;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected static function sectionsByStep(): array
    {
        return self::SECTIONS_BY_STEP;
    }

    /**
     * @return string
     */
    protected static function pipelineKey(): string
    {
        return PipelineStepConstants::PIPELINE_NOVELTIES;
    }
}

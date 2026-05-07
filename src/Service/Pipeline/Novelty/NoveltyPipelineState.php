<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;

/**
 * Polymorphic representation of one Novelty pipeline state.
 *
 * Each State knows its base transitions (next/previous, sin saltos condicionales)
 * y dos métodos de validación porque una novedad puede avanzar como individual
 * (EmployeeNovelty) o como grupo (NoveltyLiquidationDoc) según la etapa.
 *
 * Cross-cutting checks (role authorization, conditional skips, side effects)
 * are composed by the coordinator (NoveltyService).
 */
interface NoveltyPipelineState
{
    /** Estado canónico tipado. */
    public function getStatus(): PipelineStatus;

    /** Estado siguiente base (sin saltos condicionales); null si terminal. */
    public function getNextStatus(): ?PipelineStatus;

    /** Estado anterior; null si es el primero. */
    public function getPreviousStatus(): ?PipelineStatus;

    /**
     * Errores que impiden avanzar como novedad individual.
     * Si la etapa no aplica al modo individual, retornar el error correspondiente.
     *
     * @return array<string>
     */
    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array;

    /**
     * Errores que impiden avanzar como documento de liquidación grupal.
     * Si la etapa no aplica al modo grupal, retornar el error correspondiente.
     *
     * @return array<string>
     */
    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array;
}

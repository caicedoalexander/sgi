<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty;

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
    /** Canonical name (e.g. 'aprobacion'). */
    public function getName(): string;

    /** Base next state's name (sin saltos condicionales); null if terminal. */
    public function getNext(): ?string;

    /** Previous state's name; null if first state. */
    public function getPrevious(): ?string;

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

<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty;

use App\Service\Pipeline\Novelty\State\AprobacionState;
use App\Service\Pipeline\Novelty\State\AutPagoState;
use App\Service\Pipeline\Novelty\State\ContabilidadState;
use App\Service\Pipeline\Novelty\State\GdpState;
use App\Service\Pipeline\Novelty\State\PagadaState;
use App\Service\Pipeline\Novelty\State\RevisionFirmasState;
use App\Service\Pipeline\Novelty\State\RrhhState;
use App\Service\Pipeline\Novelty\State\TesoreriaState;
use InvalidArgumentException;

/**
 * Resolves `employee_novelties.pipeline_status` (string) to a concrete State.
 * Sole dependency the coordinator (NoveltyService) needs to access states.
 */
final class NoveltyPipelineStateRegistry
{
    /**
     * @var array<string, \App\Service\Pipeline\Novelty\NoveltyPipelineState>
     */
    private array $states;

    public function __construct(
        ?AprobacionState $aprobacion = null,
        ?RrhhState $rrhh = null,
        ?ContabilidadState $contabilidad = null,
        ?RevisionFirmasState $revisionFirmas = null,
        ?GdpState $gdp = null,
        ?TesoreriaState $tesoreria = null,
        ?AutPagoState $autPago = null,
        ?PagadaState $pagada = null,
    ) {
        $list = [
            $aprobacion ?? new AprobacionState(),
            $rrhh ?? new RrhhState(),
            $contabilidad ?? new ContabilidadState(),
            $revisionFirmas ?? new RevisionFirmasState(),
            $gdp ?? new GdpState(),
            $tesoreria ?? new TesoreriaState(),
            $autPago ?? new AutPagoState(),
            $pagada ?? new PagadaState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getName()] = $state;
        }
    }

    public function get(string $name): NoveltyPipelineState
    {
        if (!isset($this->states[$name])) {
            throw new InvalidArgumentException("Unknown novelty pipeline state: {$name}");
        }

        return $this->states[$name];
    }

    /** @return array<string, \App\Service\Pipeline\Novelty\NoveltyPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

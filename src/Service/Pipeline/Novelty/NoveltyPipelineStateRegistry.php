<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Service\Pipeline\Novelty\State\AprobacionState;
use App\Service\Pipeline\Novelty\State\AutorizacionPagoState;
use App\Service\Pipeline\Novelty\State\ContabilidadState;
use App\Service\Pipeline\Novelty\State\GdpState;
use App\Service\Pipeline\Novelty\State\PagadaState;
use App\Service\Pipeline\Novelty\State\RevisionFirmasState;
use App\Service\Pipeline\Novelty\State\RrhhState;
use App\Service\Pipeline\Novelty\State\TesoreriaState;
use InvalidArgumentException;

/**
 * Resolves `employee_novelties.pipeline_status` (enum) to a concrete State.
 * Sole dependency the coordinator (NoveltyService) needs to access states.
 *
 * Note: registro and rechazada no tienen State class porque son estados
 * de borde (registro = inicial sin lógica, rechazada = terminal sin lógica).
 * Llamar get() con esos cases lanza InvalidArgumentException.
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
        ?AutorizacionPagoState $autorizacionPago = null,
        ?PagadaState $pagada = null,
    ) {
        $list = [
            $aprobacion ?? new AprobacionState(),
            $rrhh ?? new RrhhState(),
            $contabilidad ?? new ContabilidadState(),
            $revisionFirmas ?? new RevisionFirmasState(),
            $gdp ?? new GdpState(),
            $tesoreria ?? new TesoreriaState(),
            $autorizacionPago ?? new AutorizacionPagoState(),
            $pagada ?? new PagadaState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getStatus()->value] = $state;
        }
    }

    public function get(PipelineStatus $status): NoveltyPipelineState
    {
        if (!isset($this->states[$status->value])) {
            throw new InvalidArgumentException("No state class for novelty pipeline status: {$status->value}");
        }

        return $this->states[$status->value];
    }

    /** @return array<string, \App\Service\Pipeline\Novelty\NoveltyPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

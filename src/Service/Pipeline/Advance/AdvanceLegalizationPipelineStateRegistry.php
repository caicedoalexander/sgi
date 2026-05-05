<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance;

use App\Service\Pipeline\Advance\State\AutorizacionPagoState;
use App\Service\Pipeline\Advance\State\ContabilidadState;
use App\Service\Pipeline\Advance\State\LegalizadaState;
use App\Service\Pipeline\Advance\State\RevisionFirmasState;
use App\Service\Pipeline\Advance\State\TesoreriaState;
use App\Service\Pipeline\Advance\State\ValidacionState;
use InvalidArgumentException;

/**
 * Resuelve `advance_legalizations.status` (string) → instancia concreta
 * de AdvanceLegalizationPipelineState. Es la única dependencia que
 * necesita el coordinador (AdvanceLegalizationService) para acceder a
 * los estados.
 */
final class AdvanceLegalizationPipelineStateRegistry
{
    /** @var array<string, \App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState> */
    private array $states;

    public function __construct(
        ?ValidacionState $validacion = null,
        ?RevisionFirmasState $revisionFirmas = null,
        ?ContabilidadState $contabilidad = null,
        ?TesoreriaState $tesoreria = null,
        ?AutorizacionPagoState $autPago = null,
        ?LegalizadaState $legalizada = null,
    ) {
        $list = [
            $validacion ?? new ValidacionState(),
            $revisionFirmas ?? new RevisionFirmasState(),
            $contabilidad ?? new ContabilidadState(),
            $tesoreria ?? new TesoreriaState(),
            $autPago ?? new AutorizacionPagoState(),
            $legalizada ?? new LegalizadaState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getName()] = $state;
        }
    }

    public function get(string $name): AdvanceLegalizationPipelineState
    {
        if (!isset($this->states[$name])) {
            throw new InvalidArgumentException("Unknown advance pipeline state: {$name}");
        }

        return $this->states[$name];
    }

    /** @return array<string, \App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

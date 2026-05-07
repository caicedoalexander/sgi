<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Service\Pipeline\PettyCash\State\AgrupacionState;
use App\Service\Pipeline\PettyCash\State\AutorizacionPagoState;
use App\Service\Pipeline\PettyCash\State\ContabilidadState;
use App\Service\Pipeline\PettyCash\State\PagadaState;
use App\Service\Pipeline\PettyCash\State\TesoreriaState;

/**
 * Resuelve `petty_cash_records.status` (enum) → instancia concreta de
 * PettyCashPipelineState. Es la única dependencia que necesita el
 * coordinador (PettyCashService) para acceder a los estados.
 */
final class PettyCashPipelineStateRegistry
{
    /**
     * @var array<string, \App\Service\Pipeline\PettyCash\PettyCashPipelineState>
     */
    private array $states;

    public function __construct(
        ?AgrupacionState $agrupacion = null,
        ?ContabilidadState $contabilidad = null,
        ?TesoreriaState $tesoreria = null,
        ?AutorizacionPagoState $autorizacionPago = null,
        ?PagadaState $pagada = null,
    ) {
        $list = [
            $agrupacion ?? new AgrupacionState(),
            $contabilidad ?? new ContabilidadState(),
            $tesoreria ?? new TesoreriaState(),
            $autorizacionPago ?? new AutorizacionPagoState(),
            $pagada ?? new PagadaState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getStatus()->value] = $state;
        }
    }

    public function get(PipelineStatus $status): PettyCashPipelineState
    {
        return $this->states[$status->value];
    }

    /** @return array<string, \App\Service\Pipeline\PettyCash\PettyCashPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Service\Pipeline\PettyCash\State\AgrupacionState;
use App\Service\Pipeline\PettyCash\State\AutorizacionPagoState;
use App\Service\Pipeline\PettyCash\State\ContabilidadState;
use App\Service\Pipeline\PettyCash\State\PagadaState;
use App\Service\Pipeline\PettyCash\State\TesoreriaState;
use App\Service\Pipeline\PettyCash\State\VerificacionPagoState;

/**
 * Resuelve `petty_cash_records.status` (enum) → instancia concreta de
 * PettyCashPipelineState. Es la única dependencia que necesita el
 * coordinador (PettyCashPipelineService) para acceder a los estados.
 */
final class PettyCashPipelineStateRegistry
{
    /**
     * @var array<string, \App\Service\Pipeline\PettyCash\PettyCashPipelineState>
     */
    private array $states;

    /**
     * @param \App\Service\Pipeline\PettyCash\State\AgrupacionState|null $agrupacion State.
     * @param \App\Service\Pipeline\PettyCash\State\ContabilidadState|null $contabilidad State.
     * @param \App\Service\Pipeline\PettyCash\State\TesoreriaState|null $tesoreria State.
     * @param \App\Service\Pipeline\PettyCash\State\AutorizacionPagoState|null $autorizacionPago State.
     * @param \App\Service\Pipeline\PettyCash\State\VerificacionPagoState|null $verificacionPago State.
     * @param \App\Service\Pipeline\PettyCash\State\PagadaState|null $pagada State.
     */
    public function __construct(
        ?AgrupacionState $agrupacion = null,
        ?ContabilidadState $contabilidad = null,
        ?TesoreriaState $tesoreria = null,
        ?AutorizacionPagoState $autorizacionPago = null,
        ?VerificacionPagoState $verificacionPago = null,
        ?PagadaState $pagada = null,
    ) {
        $list = [
            $agrupacion ?? new AgrupacionState(),
            $contabilidad ?? new ContabilidadState(),
            $tesoreria ?? new TesoreriaState(),
            $autorizacionPago ?? new AutorizacionPagoState(),
            $verificacionPago ?? new VerificacionPagoState(),
            $pagada ?? new PagadaState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getStatus()->value] = $state;
        }
    }

    /**
     * Resuelve el enum de estado a su State concreto.
     *
     * @param \App\Constants\Domain\PettyCash\PipelineStatus $status Estado tipado.
     * @return \App\Service\Pipeline\PettyCash\PettyCashPipelineState
     */
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

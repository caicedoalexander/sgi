<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Service\Pipeline\Refund\State\AgrupacionState;
use App\Service\Pipeline\Refund\State\AutorizacionPagoState;
use App\Service\Pipeline\Refund\State\ContabilidadState;
use App\Service\Pipeline\Refund\State\PagadaState;
use App\Service\Pipeline\Refund\State\TesoreriaState;
use App\Service\Pipeline\Refund\State\VerificacionPagoState;

/**
 * Resolves `refunds.status` (enum) to a concrete State.
 * Sole dependency the coordinator (RefundService) needs to access states.
 */
final class RefundPipelineStateRegistry
{
    /**
     * @var array<string, \App\Service\Pipeline\Refund\RefundPipelineState>
     */
    private array $states;

    /**
     * @param \App\Service\Pipeline\Refund\State\AgrupacionState|null $agrupacion State.
     * @param \App\Service\Pipeline\Refund\State\ContabilidadState|null $contabilidad State.
     * @param \App\Service\Pipeline\Refund\State\TesoreriaState|null $tesoreria State.
     * @param \App\Service\Pipeline\Refund\State\AutorizacionPagoState|null $autorizacionPago State.
     * @param \App\Service\Pipeline\Refund\State\PagadaState|null $pagada State.
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

    public function get(PipelineStatus $status): RefundPipelineState
    {
        return $this->states[$status->value];
    }

    /** @return array<string, \App\Service\Pipeline\Refund\RefundPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

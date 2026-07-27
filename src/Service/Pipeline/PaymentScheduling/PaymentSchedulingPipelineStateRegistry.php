<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Service\Pipeline\PaymentScheduling\State\AutorizacionPagoState;
use App\Service\Pipeline\PaymentScheduling\State\BorradorState;
use App\Service\Pipeline\PaymentScheduling\State\PagadaState;
use App\Service\Pipeline\PaymentScheduling\State\TesoreriaState;
use App\Service\Pipeline\PaymentScheduling\State\VerificacionPagoState;

/**
 * Resolves `payment_schedulings.pipeline_status` (enum) to a concrete State.
 * Sole dependency the coordinator (PaymentSchedulingPipelineService) needs to access states.
 */
final class PaymentSchedulingPipelineStateRegistry
{
    /**
     * @var array<string, \App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState>
     */
    private array $states;

    /**
     * @param \App\Service\Pipeline\PaymentScheduling\State\BorradorState|null $borrador State.
     * @param \App\Service\Pipeline\PaymentScheduling\State\TesoreriaState|null $tesoreria State.
     * @param \App\Service\Pipeline\PaymentScheduling\State\AutorizacionPagoState|null $autorizacionPago State.
     * @param \App\Service\Pipeline\PaymentScheduling\State\VerificacionPagoState|null $verificacionPago State.
     * @param \App\Service\Pipeline\PaymentScheduling\State\PagadaState|null $pagada State.
     */
    public function __construct(
        ?BorradorState $borrador = null,
        ?TesoreriaState $tesoreria = null,
        ?AutorizacionPagoState $autorizacionPago = null,
        ?VerificacionPagoState $verificacionPago = null,
        ?PagadaState $pagada = null,
    ) {
        $list = [
            $borrador ?? new BorradorState(),
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
     * @param \App\Constants\Domain\PaymentScheduling\PipelineStatus $status Estado tipado.
     * @return \App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState
     */
    public function get(PipelineStatus $status): PaymentSchedulingPipelineState
    {
        return $this->states[$status->value];
    }

    /** @return array<string, \App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

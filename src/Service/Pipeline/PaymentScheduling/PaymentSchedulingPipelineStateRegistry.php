<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Service\Pipeline\PaymentScheduling\State\AutorizacionPagoState;
use App\Service\Pipeline\PaymentScheduling\State\BorradorState;
use App\Service\Pipeline\PaymentScheduling\State\PagadaState;
use App\Service\Pipeline\PaymentScheduling\State\TesoreriaState;

/**
 * Resolves `payment_schedulings.pipeline_status` (enum) to a concrete State.
 * Sole dependency the coordinator (PaymentSchedulingService) needs to access states.
 */
final class PaymentSchedulingPipelineStateRegistry
{
    /**
     * @var array<string, \App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState>
     */
    private array $states;

    public function __construct(
        ?BorradorState $borrador = null,
        ?TesoreriaState $tesoreria = null,
        ?AutorizacionPagoState $autorizacionPago = null,
        ?PagadaState $pagada = null,
    ) {
        $list = [
            $borrador ?? new BorradorState(),
            $tesoreria ?? new TesoreriaState(),
            $autorizacionPago ?? new AutorizacionPagoState(),
            $pagada ?? new PagadaState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getStatus()->value] = $state;
        }
    }

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

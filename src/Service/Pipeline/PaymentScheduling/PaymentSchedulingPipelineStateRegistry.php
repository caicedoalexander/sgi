<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling;

use App\Service\Pipeline\PaymentScheduling\State\AutPagoState;
use App\Service\Pipeline\PaymentScheduling\State\BorradorState;
use App\Service\Pipeline\PaymentScheduling\State\PagadaState;
use App\Service\Pipeline\PaymentScheduling\State\TesoreriaState;
use InvalidArgumentException;

/**
 * Resolves `payment_schedulings.pipeline_status` (string) to a concrete State.
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
        ?AutPagoState $autPago = null,
        ?PagadaState $pagada = null,
    ) {
        $list = [
            $borrador ?? new BorradorState(),
            $tesoreria ?? new TesoreriaState(),
            $autPago ?? new AutPagoState(),
            $pagada ?? new PagadaState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getName()] = $state;
        }
    }

    public function get(string $name): PaymentSchedulingPipelineState
    {
        if (!isset($this->states[$name])) {
            throw new InvalidArgumentException("Unknown payment scheduling pipeline state: {$name}");
        }

        return $this->states[$name];
    }

    /** @return array<string, \App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund;

use App\Service\Pipeline\Refund\State\AgrupacionState;
use App\Service\Pipeline\Refund\State\AutPagoState;
use App\Service\Pipeline\Refund\State\ContabilidadState;
use App\Service\Pipeline\Refund\State\PagadoState;
use App\Service\Pipeline\Refund\State\TesoreriaState;
use InvalidArgumentException;

/**
 * Resolves `refunds.status` (string) to a concrete State.
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
     * @param \App\Service\Pipeline\Refund\State\AutPagoState|null $autPago State.
     * @param \App\Service\Pipeline\Refund\State\PagadoState|null $pagado State.
     */
    public function __construct(
        ?AgrupacionState $agrupacion = null,
        ?ContabilidadState $contabilidad = null,
        ?TesoreriaState $tesoreria = null,
        ?AutPagoState $autPago = null,
        ?PagadoState $pagado = null,
    ) {
        $list = [
            $agrupacion ?? new AgrupacionState(),
            $contabilidad ?? new ContabilidadState(),
            $tesoreria ?? new TesoreriaState(),
            $autPago ?? new AutPagoState(),
            $pagado ?? new PagadoState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getName()] = $state;
        }
    }

    /**
     * @param string $name State name.
     */
    public function get(string $name): RefundPipelineState
    {
        if (!isset($this->states[$name])) {
            throw new InvalidArgumentException("Unknown refund pipeline state: {$name}");
        }

        return $this->states[$name];
    }

    /** @return array<string, \App\Service\Pipeline\Refund\RefundPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

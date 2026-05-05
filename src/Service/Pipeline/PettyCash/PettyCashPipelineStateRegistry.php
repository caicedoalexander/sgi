<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash;

use App\Service\Pipeline\PettyCash\State\AgrupacionState;
use App\Service\Pipeline\PettyCash\State\AutPagoState;
use App\Service\Pipeline\PettyCash\State\ContabilidadState;
use App\Service\Pipeline\PettyCash\State\PagadoState;
use App\Service\Pipeline\PettyCash\State\TesoreriaState;
use InvalidArgumentException;

/**
 * Resuelve `petty_cash_records.status` (string) → instancia concreta de
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

    public function get(string $name): PettyCashPipelineState
    {
        if (!isset($this->states[$name])) {
            throw new InvalidArgumentException("Unknown petty cash pipeline state: {$name}");
        }

        return $this->states[$name];
    }

    /** @return array<string, \App\Service\Pipeline\PettyCash\PettyCashPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

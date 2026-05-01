<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Service\Pipeline\State\AprobacionState;
use App\Service\Pipeline\State\AutorizacionPagoState;
use App\Service\Pipeline\State\ContabilidadState;
use App\Service\Pipeline\State\LegalizadaState;
use App\Service\Pipeline\State\PagadaState;
use App\Service\Pipeline\State\TesoreriaState;
use InvalidArgumentException;

/**
 * Resuelve `pipeline_status` (string) → instancia concreta de InvoicePipelineState.
 * Es la única dependencia que necesita el coordinador para acceder a los estados.
 */
final class InvoicePipelineStateRegistry
{
    /** @var array<string, InvoicePipelineState> */
    private array $states;

    public function __construct(
        AprobacionState $aprobacion,
        ContabilidadState $contabilidad,
        TesoreriaState $tesoreria,
        AutorizacionPagoState $autorizacionPago,
        PagadaState $pagada,
        LegalizadaState $legalizada,
    ) {
        foreach ([$aprobacion, $contabilidad, $tesoreria, $autorizacionPago, $pagada, $legalizada] as $state) {
            $this->states[$state->getName()] = $state;
        }
    }

    public function get(string $name): InvoicePipelineState
    {
        if (!isset($this->states[$name])) {
            throw new InvalidArgumentException("Unknown pipeline state: {$name}");
        }

        return $this->states[$name];
    }

    /** @return array<string, InvoicePipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

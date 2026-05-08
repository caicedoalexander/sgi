<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Service\Pipeline\State\AprobacionState;
use App\Service\Pipeline\State\AutorizacionPagoState;
use App\Service\Pipeline\State\ContabilidadState;
use App\Service\Pipeline\State\LegalizadaState;
use App\Service\Pipeline\State\PagadaState;
use App\Service\Pipeline\State\TesoreriaState;
use App\Service\Pipeline\State\VerificacionPagoState;

/**
 * Resuelve `pipeline_status` (enum) → instancia concreta de InvoicePipelineState.
 * Es la única dependencia que necesita el coordinador para acceder a los estados.
 */
final class InvoicePipelineStateRegistry
{
    /**
     * @var array<string, \App\Service\Pipeline\InvoicePipelineState>
     */
    private array $states;

    public function __construct(
        AprobacionState $aprobacion,
        ContabilidadState $contabilidad,
        TesoreriaState $tesoreria,
        AutorizacionPagoState $autorizacionPago,
        VerificacionPagoState $verificacionPago,
        PagadaState $pagada,
        LegalizadaState $legalizada,
    ) {
        foreach ([$aprobacion, $contabilidad, $tesoreria, $autorizacionPago, $verificacionPago, $pagada, $legalizada] as $state) {
            $this->states[$state->getStatus()->value] = $state;
        }
    }

    public function get(PipelineStatus $status): InvoicePipelineState
    {
        return $this->states[$status->value];
    }

    /** @return array<string, \App\Service\Pipeline\InvoicePipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

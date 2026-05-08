<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Service\Pipeline\Invoice\State\AprobacionState;
use App\Service\Pipeline\Invoice\State\AutorizacionPagoState;
use App\Service\Pipeline\Invoice\State\ContabilidadState;
use App\Service\Pipeline\Invoice\State\LegalizadaState;
use App\Service\Pipeline\Invoice\State\PagadaState;
use App\Service\Pipeline\Invoice\State\TesoreriaState;
use App\Service\Pipeline\Invoice\State\VerificacionPagoState;

/**
 * Resuelve `pipeline_status` (enum) → instancia concreta de InvoicePipelineState.
 * Es la única dependencia que necesita el coordinador para acceder a los estados.
 */
final class InvoicePipelineStateRegistry
{
    /**
     * @var array<string, \App\Service\Pipeline\Invoice\InvoicePipelineState>
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

    /** @return array<string, \App\Service\Pipeline\Invoice\InvoicePipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

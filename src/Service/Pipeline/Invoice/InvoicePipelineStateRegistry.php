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

    /**
     * @param \App\Service\Pipeline\Invoice\State\AprobacionState $aprobacion Estado aprobación.
     * @param \App\Service\Pipeline\Invoice\State\ContabilidadState $contabilidad Estado contabilidad.
     * @param \App\Service\Pipeline\Invoice\State\TesoreriaState $tesoreria Estado tesorería.
     * @param \App\Service\Pipeline\Invoice\State\AutorizacionPagoState $autorizacionPago Estado autorización de pago.
     * @param \App\Service\Pipeline\Invoice\State\VerificacionPagoState $verificacionPago Estado verificación de pago.
     * @param \App\Service\Pipeline\Invoice\State\PagadaState $pagada Estado pagada.
     * @param \App\Service\Pipeline\Invoice\State\LegalizadaState $legalizada Estado legalizada (terminal).
     */
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

    /**
     * Resuelve el enum de estado a su instancia concreta de State.
     *
     * @param \App\Constants\Domain\Invoice\PipelineStatus $status Estado del pipeline.
     * @return \App\Service\Pipeline\Invoice\InvoicePipelineState
     */
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

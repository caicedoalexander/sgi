<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Service\Pipeline\Advance\State\AprobacionState;
use App\Service\Pipeline\Advance\State\AutorizacionPagoState;
use App\Service\Pipeline\Advance\State\ContabilidadState;
use App\Service\Pipeline\Advance\State\LegalizadaState;
use App\Service\Pipeline\Advance\State\RevisionFirmasState;
use App\Service\Pipeline\Advance\State\TesoreriaState;
use App\Service\Pipeline\Advance\State\ValidacionState;
use App\Service\Pipeline\Advance\State\VerificacionPagoState;

/**
 * Resuelve `advance_legalizations.status` (enum) → instancia concreta
 * de AdvanceLegalizationPipelineState. Es la única dependencia que
 * necesita el coordinador (AdvanceLegalizationService) para acceder a
 * los estados.
 */
final class AdvanceLegalizationPipelineStateRegistry
{
    /**
     * @var array<string, \App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState>
     */
    private array $states;

    /**
     * @param \App\Service\Pipeline\Advance\State\ValidacionState|null $validacion Estado validación.
     * @param \App\Service\Pipeline\Advance\State\AprobacionState|null $aprobacion Estado aprobación.
     * @param \App\Service\Pipeline\Advance\State\RevisionFirmasState|null $revisionFirmas Estado revisión y firmas.
     * @param \App\Service\Pipeline\Advance\State\ContabilidadState|null $contabilidad Estado contabilidad.
     * @param \App\Service\Pipeline\Advance\State\TesoreriaState|null $tesoreria Estado tesorería.
     * @param \App\Service\Pipeline\Advance\State\AutorizacionPagoState|null $autPago Estado autorización de pago.
     * @param \App\Service\Pipeline\Advance\State\VerificacionPagoState|null $verificacionPago Estado verificación de pago.
     * @param \App\Service\Pipeline\Advance\State\LegalizadaState|null $legalizada Estado legalizada (terminal).
     */
    public function __construct(
        ?ValidacionState $validacion = null,
        ?AprobacionState $aprobacion = null,
        ?RevisionFirmasState $revisionFirmas = null,
        ?ContabilidadState $contabilidad = null,
        ?TesoreriaState $tesoreria = null,
        ?AutorizacionPagoState $autPago = null,
        ?VerificacionPagoState $verificacionPago = null,
        ?LegalizadaState $legalizada = null,
    ) {
        $list = [
            $validacion ?? new ValidacionState(),
            $aprobacion ?? new AprobacionState(),
            $revisionFirmas ?? new RevisionFirmasState(),
            $contabilidad ?? new ContabilidadState(),
            $tesoreria ?? new TesoreriaState(),
            $autPago ?? new AutorizacionPagoState(),
            $verificacionPago ?? new VerificacionPagoState(),
            $legalizada ?? new LegalizadaState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getStatus()->value] = $state;
        }
    }

    /**
     * Resuelve el enum de estado a su instancia concreta de State.
     *
     * @param \App\Constants\Domain\Advance\PipelineStatus $status Estado del pipeline de legalización.
     * @return \App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState
     */
    public function get(PipelineStatus $status): AdvanceLegalizationPipelineState
    {
        return $this->states[$status->value];
    }

    /** @return array<string, \App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}

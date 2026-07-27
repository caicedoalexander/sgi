<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

final class ContabilidadState implements AdvanceLegalizationPipelineState
{
    /**
     * Estado canónico tipado de este State.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    /**
     * Estado siguiente natural en el avance lineal; null si es terminal o bifurcante.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior; null si es el primero o si la regresión está bloqueada.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * Gate del paso Contabilidad: la causación (causada + fecha + lista para
     * pago) es obligatoria para cualquiera de las tres salidas del paso (caso
     * exacto, faltante, sobrante). Espejo de
     * `Pipeline\Invoice\State\ContabilidadState::validateAdvance()`.
     *
     * @return array<string>
     */
    public function validateAdvance(AdvanceLegalization $leg): array
    {
        $errors = [];
        if (!(bool)($leg->accrued ?? false)) {
            $errors[] = 'La legalización debe estar marcada como Causada';
        }
        $accrualDate = $leg->accrual_date ?? null;
        if ($accrualDate === null || $accrualDate === '' || $accrualDate === false) {
            $errors[] = 'Fecha de Causación es requerida';
        }
        $readyForPayment = $leg->ready_for_payment ?? null;
        if ($readyForPayment === null || $readyForPayment === '' || $readyForPayment === false) {
            $errors[] = 'Campo "Lista para Pago" es requerido';
        }

        return $errors;
    }
}

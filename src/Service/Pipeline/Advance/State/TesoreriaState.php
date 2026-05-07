<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

final class TesoreriaState implements AdvanceLegalizationPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        // Bifurca por case_type:
        //   - faltante → confirmShortageReceipt → legalizada (salta aut_pago)
        //   - sobrante → registerRefundPayment  → autorizacion_pago → legalizada
        // Sin "next" lineal.
        return null;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        return [];
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

final class TesoreriaState implements AdvanceLegalizationPipelineState
{
    public function getName(): string
    {
        return AdvanceConstants::STATUS_TESORERIA;
    }

    public function getNext(): ?string
    {
        // Bifurca por case_type:
        //   - faltante → confirmShortageReceipt → legalizada (salta aut_pago)
        //   - sobrante → registerRefundPayment  → autorizacion_pago → legalizada
        // Sin "next" lineal.
        return null;
    }

    public function getPrevious(): ?string
    {
        return AdvanceConstants::STATUS_CONTABILIDAD;
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        return [];
    }
}

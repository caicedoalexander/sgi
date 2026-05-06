<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class TesoreriaState implements PaymentSchedulingPipelineState
{
    public function getName(): string
    {
        return PaymentSchedulingConstants::STATUS_TESORERIA;
    }

    public function getNext(): ?string
    {
        return PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO;
    }

    public function getPrevious(): ?string
    {
        return PaymentSchedulingConstants::STATUS_BORRADOR;
    }

    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return [];
    }
}

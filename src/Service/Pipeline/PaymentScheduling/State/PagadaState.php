<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class PagadaState implements PaymentSchedulingPipelineState
{
    public function getName(): string
    {
        return PaymentSchedulingConstants::STATUS_PAGADA;
    }

    public function getNext(): ?string
    {
        return null;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return [];
    }
}

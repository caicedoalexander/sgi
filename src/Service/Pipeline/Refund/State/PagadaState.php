<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class PagadaState implements RefundPipelineState
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return RefundConstants::STATUS_PAGADA;
    }

    /**
     * @inheritDoc
     */
    public function getNext(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getPrevious(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateAdvance(Refund $record): array
    {
        return ['Este registro ya está en su estado final.'];
    }

    /**
     * @inheritDoc
     */
    public function getRegressionLockMessage(Refund $record): ?string
    {
        return null;
    }
}

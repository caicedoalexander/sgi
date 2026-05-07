<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;
use Cake\ORM\TableRegistry;

final class BorradorState implements PaymentSchedulingPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::BORRADOR;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::TESORERIA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return null;
    }

    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
        $count = $itemsTable->find()
            ->where(['payment_scheduling_id' => $scheduling->id])
            ->count();

        if ($count === 0) {
            return ['Debe vincular al menos una factura'];
        }

        return [];
    }
}

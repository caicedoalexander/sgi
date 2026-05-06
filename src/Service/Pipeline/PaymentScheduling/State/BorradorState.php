<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;
use Cake\ORM\TableRegistry;

final class BorradorState implements PaymentSchedulingPipelineState
{
    public function getName(): string
    {
        return PaymentSchedulingConstants::STATUS_BORRADOR;
    }

    public function getNext(): ?string
    {
        return PaymentSchedulingConstants::STATUS_TESORERIA;
    }

    public function getPrevious(): ?string
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

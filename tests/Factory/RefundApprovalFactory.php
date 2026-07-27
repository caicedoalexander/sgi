<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\InvoiceConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de RefundApproval. El caller provee refund_id y user_id (FK NOT NULL).
 */
class RefundApprovalFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'RefundApprovals';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
        ];
    }

    public function approved(): static
    {
        return $this->setField('status', InvoiceConstants::APPROVER_STATUS_APPROVED);
    }
}

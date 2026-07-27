<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\InvoiceConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de AdvanceLegalizationApproval. El caller provee
 * advance_legalization_id y user_id (FK NOT NULL).
 */
class AdvanceLegalizationApprovalFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'AdvanceLegalizationApprovals';
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

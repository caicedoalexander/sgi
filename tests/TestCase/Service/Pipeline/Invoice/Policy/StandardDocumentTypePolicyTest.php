<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Policy;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\Policy\StandardDocumentTypePolicy;
use App\Service\Pipeline\Invoice\State\AprobacionState;
use PHPUnit\Framework\TestCase;
use stdClass;

final class StandardDocumentTypePolicyTest extends TestCase
{
    private StandardDocumentTypePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new StandardDocumentTypePolicy();
    }

    public function testDocumentTypeIsWildcard(): void
    {
        $this->assertSame('*', $this->policy->getDocumentType());
    }

    public function testBlocksAdvanceAlwaysNull(): void
    {
        $this->assertNull($this->policy->blocksAdvance(new AprobacionState(), new stdClass()));
    }

    public function testPipelineStatusesForViewIsTheFullSixStepFlow(): void
    {
        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES, $this->policy->getPipelineStatusesForView());
    }

    public function testFilterVisibleSectionsReturnsSameArray(): void
    {
        $input = ['ledger', 'revision', 'accounting'];
        $this->assertSame($input, $this->policy->filterVisibleSections($input));
    }

    public function testTriggersAutoLegalizationAlwaysFalse(): void
    {
        foreach (PipelineStatus::cases() as $case) {
            $this->assertFalse($this->policy->triggersAutoLegalization($case), $case->value);
        }
    }

    public function testRegressionLockReasonAlwaysNull(): void
    {
        $this->assertNull($this->policy->getRegressionLockReason(new stdClass()));
    }

    public function testAllowsRefundPaymentsFalse(): void
    {
        $this->assertFalse($this->policy->allowsRefundPayments());
    }
}

<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Policy;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\Policy\LegalizacionDocumentTypePolicy;
use App\Service\Pipeline\Invoice\State\AprobacionState;
use App\Service\Pipeline\Invoice\State\ContabilidadState;
use App\Service\Pipeline\Invoice\State\PagadaState;
use PHPUnit\Framework\TestCase;
use stdClass;

final class LegalizacionDocumentTypePolicyTest extends TestCase
{
    private LegalizacionDocumentTypePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new LegalizacionDocumentTypePolicy();
    }

    public function testDocumentTypeIsLegalizacion(): void
    {
        $this->assertSame(InvoiceConstants::DOCTYPE_LEGALIZACION, $this->policy->getDocumentType());
    }

    public function testBlocksAdvanceInContabilidad(): void
    {
        $msg = $this->policy->blocksAdvance(new ContabilidadState(), new stdClass());
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Anticipo padre', $msg);
    }

    public function testBlocksAdvanceNullInOtherStates(): void
    {
        $this->assertNull($this->policy->blocksAdvance(new AprobacionState(), new stdClass()));
        $this->assertNull($this->policy->blocksAdvance(new PagadaState(), new stdClass()));
    }

    public function testPipelineStatusesForViewIsLegalizationThreeStep(): void
    {
        $this->assertSame(
            InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION,
            $this->policy->getPipelineStatusesForView(),
        );
    }

    public function testFilterVisibleSectionsRemovesTreasuryAndPaymentAuthorization(): void
    {
        $input = ['ledger', 'revision', 'accounting', 'treasury', 'payment_authorization'];
        $expected = ['ledger', 'revision', 'accounting'];
        $this->assertSame($expected, $this->policy->filterVisibleSections($input));
    }

    public function testFilterVisibleSectionsKeepsUnrelatedSections(): void
    {
        $this->assertSame(['ledger'], $this->policy->filterVisibleSections(['ledger']));
    }

    public function testTriggersAutoLegalizationAlwaysFalse(): void
    {
        foreach (PipelineStatus::cases() as $case) {
            $this->assertFalse($this->policy->triggersAutoLegalization($case));
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

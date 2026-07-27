<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Policy;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationService;
use App\Service\Pipeline\Invoice\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Invoice\State\AprobacionState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AnticipoDocumentTypePolicyTest extends TestCase
{
    private function makePolicy(?AdvanceLegalizationService $svc = null): AnticipoDocumentTypePolicy
    {
        return new AnticipoDocumentTypePolicy(
            $svc ?? $this->createStub(AdvanceLegalizationService::class),
        );
    }

    public function testDocumentTypeIsAnticipo(): void
    {
        $this->assertSame(InvoiceConstants::DOCTYPE_ANTICIPO, $this->makePolicy()->getDocumentType());
    }

    public function testBlocksAdvanceAlwaysNull(): void
    {
        $this->assertNull($this->makePolicy()->blocksAdvance(new AprobacionState(), new stdClass()));
    }

    public function testPipelineStatusesForViewIsFullSixStep(): void
    {
        $this->assertSame(
            InvoiceConstants::PIPELINE_STATUSES,
            $this->makePolicy()->getPipelineStatusesForView(),
        );
    }

    public function testFilterVisibleSectionsRemovesRevision(): void
    {
        $sections = ['ledger', 'revision', 'accounting', 'treasury'];
        $this->assertSame(
            ['ledger', 'accounting', 'treasury'],
            $this->makePolicy()->filterVisibleSections($sections),
        );
    }

    #[DataProvider('autoLegalizationCases')]
    public function testTriggersAutoLegalizationOnlyForPagada(PipelineStatus $status, bool $expected): void
    {
        $this->assertSame($expected, $this->makePolicy()->triggersAutoLegalization($status), $status->value);
    }

    /**
     * @return array<string, array{PipelineStatus, bool}>
     */
    public static function autoLegalizationCases(): array
    {
        return [
            'aprobacion' => [PipelineStatus::APROBACION, false],
            'contabilidad' => [PipelineStatus::CONTABILIDAD, false],
            'tesoreria' => [PipelineStatus::TESORERIA, false],
            'autorizacion_pago' => [PipelineStatus::AUTORIZACION_PAGO, false],
            'verificacion_pago' => [PipelineStatus::VERIFICACION_PAGO, false],
            'pagada' => [PipelineStatus::PAGADA, true],
            'legalizada' => [PipelineStatus::LEGALIZADA, false],
        ];
    }

    public function testRegressionLockReasonNullWhenNoIdOnInvoice(): void
    {
        $svc = $this->createMock(AdvanceLegalizationService::class);
        $svc->expects($this->never())->method('hasLegalization');
        $policy = $this->makePolicy($svc);

        $this->assertNull($policy->getRegressionLockReason(new stdClass()));
    }

    public function testRegressionLockReasonNullWhenNoLegalization(): void
    {
        $svc = $this->createMock(AdvanceLegalizationService::class);
        $svc->expects($this->once())
            ->method('hasLegalization')
            ->with(5)
            ->willReturn(false);

        $policy = $this->makePolicy($svc);
        $invoice = new stdClass();
        $invoice->id = 5;
        $this->assertNull($policy->getRegressionLockReason($invoice));
    }

    public function testRegressionLockReasonMessageWhenLegalizationStarted(): void
    {
        $svc = $this->createStub(AdvanceLegalizationService::class);
        $svc->method('hasLegalization')->willReturn(true);
        $policy = $this->makePolicy($svc);

        $invoice = new stdClass();
        $invoice->id = 5;
        $msg = $policy->getRegressionLockReason($invoice);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('legalización', $msg);
    }

    public function testAllowsRefundPaymentsTrue(): void
    {
        $this->assertTrue($this->makePolicy()->allowsRefundPayments());
    }
}

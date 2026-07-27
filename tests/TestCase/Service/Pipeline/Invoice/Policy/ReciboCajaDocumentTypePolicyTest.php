<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Policy;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\InvoicePipelineState;
use App\Service\Pipeline\Invoice\Policy\ReciboCajaDocumentTypePolicy;
use PHPUnit\Framework\TestCase;

final class ReciboCajaDocumentTypePolicyTest extends TestCase
{
    private function stateFor(PipelineStatus $status): InvoicePipelineState
    {
        $state = $this->createStub(InvoicePipelineState::class);
        $state->method('getStatus')->willReturn($status);

        return $state;
    }

    public function testGetDocumentTypeIsReciboCaja(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $this->assertSame(InvoiceConstants::DOCTYPE_RECIBO_CAJA, $policy->getDocumentType());
    }

    public function testBlocksAdvanceWhenLinkedInContabilidad(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => 123];

        $this->assertNotNull(
            $policy->blocksAdvance($this->stateFor(PipelineStatus::CONTABILIDAD), $invoice),
        );
    }

    public function testDoesNotBlockWhenNotLinked(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => null];

        $this->assertNull(
            $policy->blocksAdvance($this->stateFor(PipelineStatus::CONTABILIDAD), $invoice),
        );
    }

    public function testDoesNotBlockOutsideContabilidad(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => 123];

        $this->assertNull(
            $policy->blocksAdvance($this->stateFor(PipelineStatus::TESORERIA), $invoice),
        );
    }

    public function testUsesFullPipelineForView(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES, $policy->getPipelineStatusesForView());
    }

    public function testUsesLegalizationPipelineWhenLinked(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => 123];

        $this->assertSame(
            InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION,
            $policy->getPipelineStatusesForView($invoice),
        );
    }

    public function testUsesFullPipelineWhenUnlinked(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => null];

        $this->assertSame(
            InvoiceConstants::PIPELINE_STATUSES,
            $policy->getPipelineStatusesForView($invoice),
        );
    }

    public function testHidesTreasurySectionsWhenLinked(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => 123];

        $this->assertSame(
            ['ledger', 'accounting'],
            $policy->filterVisibleSections(['ledger', 'accounting', 'treasury', 'payment_authorization'], $invoice),
        );
    }

    public function testKeepsAllSectionsWhenUnlinked(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => null];
        $input = ['ledger', 'treasury', 'payment_authorization'];

        $this->assertSame($input, $policy->filterVisibleSections($input, $invoice));
    }
}

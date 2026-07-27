<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use App\Service\Dto\PendingItem;
use App\View\Presentation\PendingPresentation;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

class PendingPresentationTest extends TestCase
{
    private function item(string $module, string $status): PendingItem
    {
        return new PendingItem(
            module: $module,
            entityId: 7,
            code: 'X-1',
            counterparty: 'Contraparte',
            summary: '$ 100',
            status: $status,
            date: new DateTime('2026-07-20'),
        );
    }

    public function testInvoiceRowHasPipelineMiniWithCorrectStage(): void
    {
        $row = PendingPresentation::forRow($this->item('invoices', InvoiceConstants::STATUS_TESORERIA));

        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES, $row->pipelineSteps);
        $this->assertSame(
            array_search(InvoiceConstants::STATUS_TESORERIA, InvoiceConstants::PIPELINE_STATUSES, true),
            $row->stageIdx,
        );
        $this->assertSame('Factura', $row->moduleLabel);
        $this->assertSame('2026-07-20', (new DateTime('2026-07-20'))->format('Y-m-d'));
        $this->assertSame(['controller' => 'Invoices', 'action' => 'edit', 7], $row->route);
    }

    public function testAdvanceUsesInvoiceStepSetNotAdvance(): void
    {
        $row = PendingPresentation::forRow($this->item('advances', InvoiceConstants::STATUS_CONTABILIDAD));

        // Anticipo = Invoice: sus pasos son los de facturas, NO los de legalización.
        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES, $row->pipelineSteps);
        $this->assertSame('Anticipo', $row->moduleLabel);
    }

    public function testNoveltyIsPillOnly(): void
    {
        $row = PendingPresentation::forRow($this->item('novelties', NoveltyConstants::STATUS_CONTABILIDAD));

        $this->assertSame([], $row->pipelineSteps);
        $this->assertSame(-1, $row->stageIdx);
        $this->assertSame('', $row->pipelineVariant);
        $this->assertSame('Novedad', $row->moduleLabel);
    }

    public function testUnknownStatusFallsBackToMutedPill(): void
    {
        $row = PendingPresentation::forRow($this->item('invoices', 'estado_inexistente'));

        $this->assertSame('pill-muted', $row->pillClass);
        $this->assertSame(-1, $row->stageIdx);
    }
}

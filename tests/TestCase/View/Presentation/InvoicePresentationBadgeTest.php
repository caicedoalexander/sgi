<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Test\Factory\BankingEntityFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PaymentSchedulingFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RefundFactory;
use App\View\Presentation\InvoicePresentation;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * Derivación del badge de vinculación en forRow(). Toca la base de datos (las
 * asociaciones de padre se cargan con contain), por eso extiende
 * Cake\TestSuite\TestCase — no el InvoicePresentationTest puro.
 */
final class InvoicePresentationBadgeTest extends TestCase
{
    public function testContainedInvoiceGetsSolidBadgeAndLosesItsPipeline(): void
    {
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $invoice = TableRegistry::getTableLocator()->get('Invoices')
            ->find()->contain(['Refunds'])->where(['refund_id' => $refund->id])->firstOrFail();

        $row = InvoicePresentation::forRow($invoice);

        $this->assertNotNull($row->linkBadge);
        $this->assertSame($refund->code, $row->linkBadge->code);
        $this->assertSame('Reintegro', $row->linkBadge->label);
        $this->assertTrue($row->linkBadge->isContainment);
        // El padre gobierna el pipeline: la factura no dibuja el suyo.
        $this->assertSame(-1, $row->stageIdx);
        $this->assertSame('pill-muted', $row->pillClass);
    }

    public function testAdvanceLinkedInvoiceGetsSolidBadgeFromInvoiceNumber(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])
            ->legalizacion()->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $invoice = TableRegistry::getTableLocator()->get('Invoices')
            ->find()->contain(['Advance'])->where(['Invoices.advance_id' => $anticipo->id])->firstOrFail();

        $row = InvoicePresentation::forRow($invoice);

        $this->assertNotNull($row->linkBadge);
        // Rama riesgosa: el código del badge sale de invoice_number, no de code.
        $this->assertSame($anticipo->invoice_number, $row->linkBadge->code);
        $this->assertSame('Anticipo', $row->linkBadge->label);
        $this->assertTrue($row->linkBadge->isContainment);
        $this->assertSame('pill-muted', $row->linkBadge->pillClass);
        // El padre gobierna el pipeline: la factura no dibuja el suyo.
        $this->assertSame(-1, $row->stageIdx);
    }

    public function testPettyCashLinkedInvoiceGetsSolidBadgeAndLosesItsPipeline(): void
    {
        $record = PettyCashRecordFactory::new()->save();
        InvoiceFactory::new(['petty_cash_record_id' => $record->id])->save();

        $invoice = TableRegistry::getTableLocator()->get('Invoices')
            ->find()->contain(['PettyCashRecords'])->where(['petty_cash_record_id' => $record->id])->firstOrFail();

        $row = InvoicePresentation::forRow($invoice);

        $this->assertNotNull($row->linkBadge);
        $this->assertSame($record->code, $row->linkBadge->code);
        $this->assertSame('Caja menor', $row->linkBadge->label);
        $this->assertTrue($row->linkBadge->isContainment);
        // El padre gobierna el pipeline: la factura no dibuja el suyo.
        $this->assertSame(-1, $row->stageIdx);
    }

    public function testScheduledInvoiceGetsDashedBadgeAndKeepsItsPipeline(): void
    {
        $scheduling = PaymentSchedulingFactory::new()->save();
        $bank = BankingEntityFactory::new()->save();
        $saved = InvoiceFactory::new()->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();

        $items = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
        $items->saveOrFail($items->newEntity([
            'payment_scheduling_id' => $scheduling->id,
            'invoice_id' => $saved->id,
            'banking_entity_id' => $bank->id,
            'amount' => 1000.0,
        ]));

        $invoice = TableRegistry::getTableLocator()->get('Invoices')
            ->find()->contain(['PaymentSchedulingItems' => ['PaymentSchedulings']])
            ->where(['Invoices.id' => $saved->id])->firstOrFail();

        $row = InvoicePresentation::forRow($invoice);

        $this->assertNotNull($row->linkBadge);
        $this->assertSame($scheduling->code, $row->linkBadge->code);
        $this->assertFalse($row->linkBadge->isContainment);
        // La programación solo agenda el pago: la factura conserva su pipeline.
        $this->assertGreaterThanOrEqual(0, $row->stageIdx);
    }

    public function testUnlinkedInvoiceHasNoBadge(): void
    {
        $saved = InvoiceFactory::new()->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        $invoice = TableRegistry::getTableLocator()->get('Invoices')->get($saved->id);

        $this->assertNull(InvoicePresentation::forRow($invoice)->linkBadge);
    }
}

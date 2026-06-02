<?php
declare(strict_types=1);

namespace App\Test\TestCase\Factory;

use App\Test\Factory\EmployeeNoveltyFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\InvoicePaymentFactory;
use App\Test\Factory\LiquidationDocPaymentFactory;
use App\Test\Factory\NoveltyLiquidationDocFactory;
use App\Test\Factory\PaymentSchedulingFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RefundFactory;
use Cake\TestSuite\TestCase;

/**
 * Smoke test: valida que las factories encadenan sus parents NOT NULL
 * (operation_center, expense_type, user→role, banking_entity, novelty_type, etc.).
 */
final class FactorySmokeTest extends TestCase
{
    public function testInvoiceFactoryPersistsWithParents(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()->save();

        $this->assertNotEmpty($invoice->id);
        $this->assertNotEmpty($invoice->operation_center_id);
        $this->assertNotEmpty($invoice->expense_type_id);
        $this->assertNotEmpty($invoice->registered_by);
    }

    public function testInvoicePaymentFactoryPersistsForInvoice(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()->save();
        $payment = InvoicePaymentFactory::new()
            ->forInvoice($invoice)
            ->withRequiredParents(['Invoices'])
            ->save();

        $this->assertNotEmpty($payment->id);
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertNotEmpty($payment->banking_entity_id);
        $this->assertNotEmpty($payment->created_by);
    }

    public function testRefundFactoryPersistsWithParents(): void
    {
        $refund = RefundFactory::new()->save();

        $this->assertNotEmpty($refund->id);
        $this->assertNotEmpty($refund->created_by);
    }

    public function testNoveltyLiquidationDocFactoryPersistsWithParents(): void
    {
        $doc = NoveltyLiquidationDocFactory::new()->save();

        $this->assertNotEmpty($doc->id);
        $this->assertNotEmpty($doc->performed_by);
        $this->assertNotEmpty($doc->created_by);
    }

    public function testEmployeeNoveltyFactoryPersistsWithParents(): void
    {
        $novelty = EmployeeNoveltyFactory::new()->save();

        $this->assertNotEmpty($novelty->id);
        $this->assertNotEmpty($novelty->novelty_type_id);
        $this->assertNotEmpty($novelty->registered_by);
    }

    public function testLiquidationDocPaymentFactoryPersistsForDoc(): void
    {
        $doc = NoveltyLiquidationDocFactory::new()->save();
        $payment = LiquidationDocPaymentFactory::new()->forDoc($doc)->save();

        $this->assertNotEmpty($payment->id);
        $this->assertSame($doc->id, $payment->liquidation_doc_id);
        $this->assertNotEmpty($payment->banking_entity_id);
        $this->assertNotEmpty($payment->created_by);
    }

    public function testPettyCashRecordFactoryPersistsWithParents(): void
    {
        $record = PettyCashRecordFactory::new()->save();

        $this->assertNotEmpty($record->id);
        $this->assertNotEmpty($record->created_by);
    }

    public function testPaymentSchedulingFactoryPersistsWithParents(): void
    {
        $scheduling = PaymentSchedulingFactory::new()->save();

        $this->assertNotEmpty($scheduling->id);
        $this->assertNotEmpty($scheduling->created_by);
    }
}

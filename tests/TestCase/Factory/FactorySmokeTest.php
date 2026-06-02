<?php
declare(strict_types=1);

namespace App\Test\TestCase\Factory;

use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\InvoicePaymentFactory;
use Cake\TestSuite\TestCase;

/**
 * Smoke test: valida que las factories de Invoice/InvoicePayment encadenan sus
 * parents NOT NULL (operation_center, expense_type, user→role, banking_entity).
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
}

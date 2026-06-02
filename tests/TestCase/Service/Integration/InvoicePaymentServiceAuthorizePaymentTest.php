<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\InvoiceConstants;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\InvoicePaymentFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) de InvoicePaymentService::authorizePayment().
 *
 * Cubre los caminos que dependen de la BD (no posibles en pure-unit):
 * recálculo real de payment_status sobre los pagos autorizados y la
 * transición de pipeline resultante (verificacion_pago cuando el total
 * queda cubierto, tesoreria cuando el pago es parcial).
 */
final class InvoicePaymentServiceAuthorizePaymentTest extends TestCase
{
    use PaymentServiceIntegrationTrait;

    public function testAuthorizeFullPaymentMarksInvoicePaidAndAdvancesToVerification(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_AUTORIZACION_PAGO)->save();
        $user = UserFactory::new()->withRequiredParents()->save();

        $payment = InvoicePaymentFactory::new()->forInvoice($invoice)
            ->withRequiredParents(['Invoices'])->withAmount(1000.0)->save();

        $result = $this->buildPaymentService()->authorizePayment($payment->id, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame(InvoiceConstants::STATUS_VERIFICACION_PAGO, $result['newPipelineStatus']);

        $payments = $this->fetchTable('InvoicePayments');
        $this->assertSame(
            InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
            $payments->get($payment->id)->status,
        );

        $invoices = $this->fetchTable('Invoices');
        $refreshed = $invoices->get($invoice->id);
        $this->assertSame(InvoiceConstants::PAYMENT_FULL, $refreshed->payment_status);
        $this->assertSame(InvoiceConstants::STATUS_VERIFICACION_PAGO, $refreshed->pipeline_status);
    }

    public function testAuthorizePartialPaymentLeavesInvoiceInTreasury(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_AUTORIZACION_PAGO)->save();
        $user = UserFactory::new()->withRequiredParents()->save();

        $payment = InvoicePaymentFactory::new()->forInvoice($invoice)
            ->withRequiredParents(['Invoices'])->withAmount(500.0)->save();

        $result = $this->buildPaymentService()->authorizePayment($payment->id, $user->id);

        $this->assertTrue($result['success']);
        $this->assertSame(InvoiceConstants::STATUS_TESORERIA, $result['newPipelineStatus']);

        $payments = $this->fetchTable('InvoicePayments');
        $this->assertSame(
            InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
            $payments->get($payment->id)->status,
        );

        $invoices = $this->fetchTable('Invoices');
        $refreshed = $invoices->get($invoice->id);
        $this->assertSame(InvoiceConstants::PAYMENT_PARTIAL, $refreshed->payment_status);
        $this->assertSame(InvoiceConstants::STATUS_TESORERIA, $refreshed->pipeline_status);
    }
}

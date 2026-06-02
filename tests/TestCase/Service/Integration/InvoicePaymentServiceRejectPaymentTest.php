<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\InvoiceConstants;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\InvoicePaymentFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) de InvoicePaymentService::rejectPayment().
 *
 * Cubre los caminos que dependen de la BD (no posibles en pure-unit) para
 * pagos NO refund: el pago pendiente se marca como rechazado conservando el
 * motivo (no se elimina) y la factura regresa a tesorería; un pago ya
 * autorizado no puede rechazarse.
 */
final class InvoicePaymentServiceRejectPaymentTest extends TestCase
{
    use PaymentServiceIntegrationTrait;

    public function testRejectPendingPaymentMarksRejectedAndReturnsInvoiceToTreasury(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_AUTORIZACION_PAGO)->save();
        $payment = InvoicePaymentFactory::new()->forInvoice($invoice)
            ->withRequiredParents(['Invoices'])->withAmount(500.0)->save();
        $user = UserFactory::new()->withRequiredParents()->save();

        $result = $this->buildPaymentService()
            ->rejectPayment($payment->id, $user->id, 'motivo de rechazo');

        $this->assertTrue($result->success);

        // El pago NO se elimina: queda rechazado con el motivo persistido.
        $payments = $this->fetchTable('InvoicePayments');
        $reloaded = $payments->get($payment->id);
        $this->assertSame(InvoiceConstants::PAYMENT_RECORD_REJECTED, $reloaded->status);
        $this->assertSame('motivo de rechazo', $reloaded->rejection_reason);

        // La factura regresa a tesorería.
        $invoices = $this->fetchTable('Invoices');
        $this->assertSame(
            InvoiceConstants::STATUS_TESORERIA,
            $invoices->get($invoice->id)->pipeline_status,
        );
    }

    public function testRejectAuthorizedPaymentFails(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_AUTORIZACION_PAGO)->save();
        $payment = InvoicePaymentFactory::new()->forInvoice($invoice)
            ->withRequiredParents(['Invoices'])->withAmount(500.0)->authorized()->save();
        $user = UserFactory::new()->withRequiredParents()->save();

        $result = $this->buildPaymentService()
            ->rejectPayment($payment->id, $user->id, 'motivo de rechazo');

        $this->assertFalse($result->success);
        $this->assertSame(['No se puede rechazar un pago ya autorizado.'], $result->errors);

        // El pago sigue autorizado y la factura no cambia de estado.
        $payments = $this->fetchTable('InvoicePayments');
        $this->assertSame(
            InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
            $payments->get($payment->id)->status,
        );
        $invoices = $this->fetchTable('Invoices');
        $this->assertSame(
            InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            $invoices->get($invoice->id)->pipeline_status,
        );
    }
}

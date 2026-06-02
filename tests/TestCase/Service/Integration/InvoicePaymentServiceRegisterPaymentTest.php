<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\InvoiceConstants;
use App\Test\Factory\BankingEntityFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) de InvoicePaymentService::registerPayment().
 *
 * Cubre los caminos que dependen de la BD (no posibles en pure-unit):
 * avance real a autorizacion_pago, validación de saldo con suma de pagos
 * (autorizados + pendientes) e idempotencia por key.
 */
final class InvoicePaymentServiceRegisterPaymentTest extends TestCase
{
    use PaymentServiceIntegrationTrait;

    public function testRegisterPaymentPersistsPaymentAndAdvancesInvoice(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        $bank = BankingEntityFactory::new()->save();
        $user = UserFactory::new()->withRequiredParents()->save();

        $result = $this->buildPaymentService()->registerPayment($invoice->id, [
            'amount' => 600,
            'payment_date' => '2026-06-01',
            'banking_entity_id' => $bank->id,
        ], $user->id);

        $this->assertTrue($result->success);

        $invoices = $this->fetchTable('Invoices');
        $this->assertSame(
            InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            $invoices->get($invoice->id)->pipeline_status,
        );

        $payments = $this->fetchTable('InvoicePayments');
        $payment = $payments->find()->where(['invoice_id' => $invoice->id])->firstOrFail();
        $this->assertSame(InvoiceConstants::PAYMENT_RECORD_PENDING, $payment->status);
        $this->assertEqualsWithDelta(600.0, (float)$payment->amount, 0.001);
    }

    public function testRegisterPaymentRejectsAmountExceedingPendingBalance(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        $bank = BankingEntityFactory::new()->save();
        $user = UserFactory::new()->withRequiredParents()->save();

        $result = $this->buildPaymentService()->registerPayment($invoice->id, [
            'amount' => 1500, // excede el monto de la factura
            'payment_date' => '2026-06-01',
            'banking_entity_id' => $bank->id,
        ], $user->id);

        $this->assertFalse($result->success);
        $this->assertSame(['El monto del pago excede el saldo pendiente de la factura.'], $result->errors);
        $this->assertSame(0, $this->fetchTable('InvoicePayments')->find()->count());
    }

    public function testRegisterPaymentIsIdempotentByKey(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        $bank = BankingEntityFactory::new()->save();
        $user = UserFactory::new()->withRequiredParents()->save();
        $service = $this->buildPaymentService();

        $data = [
            'amount' => 300,
            'payment_date' => '2026-06-01',
            'banking_entity_id' => $bank->id,
            'idempotency_key' => 'fixed-key-123',
        ];

        $first = $service->registerPayment($invoice->id, $data, $user->id);
        $second = $service->registerPayment($invoice->id, $data, $user->id);

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        // La segunda llamada con la misma key no crea un segundo pago.
        $this->assertSame(1, $this->fetchTable('InvoicePayments')->find()->count());
    }
}

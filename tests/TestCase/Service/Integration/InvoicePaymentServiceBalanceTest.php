<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\InvoiceConstants;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\InvoicePaymentFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) de los métodos de saldo y confirmación de
 * InvoicePaymentService que dependen de consultas a la base de datos.
 *
 * Cubre recalculatePaymentStatus(), getPendingBalance(),
 * hasPendingAuthorization() y confirmPayment(). El rollback por test lo aplica
 * la estrategia global (FactoryTransactionStrategy); cada método crea sus
 * propios invoice y pagos para aislarse.
 */
final class InvoicePaymentServiceBalanceTest extends TestCase
{
    use PaymentServiceIntegrationTrait;

    public function testRecalculatePaymentStatusMarksFullWhenAuthorizedCoverTotal(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        InvoicePaymentFactory::new()->forInvoice($invoice)
            ->authorized()->withAmount(600.0)->save();
        InvoicePaymentFactory::new()->forInvoice($invoice)
            ->authorized()->withAmount(400.0)->save();

        $this->assertTrue($this->buildPaymentService()->recalculatePaymentStatus($invoice->id));

        $refreshed = $this->fetchTable('Invoices')->get($invoice->id);
        $this->assertSame(InvoiceConstants::PAYMENT_FULL, $refreshed->payment_status);
        $this->assertNotNull($refreshed->full_payment_date);
    }

    public function testRecalculatePaymentStatusMarksPartialWhenAuthorizedBelowTotal(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        InvoicePaymentFactory::new()->forInvoice($invoice)
            ->authorized()->withAmount(400.0)->save();

        $this->assertTrue($this->buildPaymentService()->recalculatePaymentStatus($invoice->id));

        $refreshed = $this->fetchTable('Invoices')->get($invoice->id);
        $this->assertSame(InvoiceConstants::PAYMENT_PARTIAL, $refreshed->payment_status);
        $this->assertNull($refreshed->full_payment_date);
    }

    public function testRecalculatePaymentStatusNullWhenNoAuthorizedPayments(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        // Pago pendiente: no debe contar como autorizado.
        InvoicePaymentFactory::new()->forInvoice($invoice)->withAmount(500.0)->save();

        $this->assertTrue($this->buildPaymentService()->recalculatePaymentStatus($invoice->id));

        $refreshed = $this->fetchTable('Invoices')->get($invoice->id);
        $this->assertNull($refreshed->payment_status);
        $this->assertNull($refreshed->full_payment_date);
    }

    public function testGetPendingBalanceSubtractsAuthorizedPayments(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        InvoicePaymentFactory::new()->forInvoice($invoice)
            ->authorized()->withAmount(300.0)->save();
        // Pago pendiente: no reduce el saldo (solo cuentan los autorizados).
        InvoicePaymentFactory::new()->forInvoice($invoice)->withAmount(200.0)->save();

        $balance = $this->buildPaymentService()->getPendingBalance($invoice->id);

        $this->assertEqualsWithDelta(700.0, $balance, 0.001);
    }

    public function testGetPendingBalanceNeverNegativeOnOverpayment(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        InvoicePaymentFactory::new()->forInvoice($invoice)
            ->authorized()->withAmount(1200.0)->save();

        $balance = $this->buildPaymentService()->getPendingBalance($invoice->id);

        $this->assertEqualsWithDelta(0.0, $balance, 0.001);
    }

    public function testHasPendingAuthorizationTrueWhenPendingPaymentExists(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_AUTORIZACION_PAGO)->save();
        InvoicePaymentFactory::new()->forInvoice($invoice)->withAmount(500.0)->save();

        $this->assertTrue($this->buildPaymentService()->hasPendingAuthorization($invoice->id));
    }

    public function testHasPendingAuthorizationFalseWhenNoPendingPayment(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        InvoicePaymentFactory::new()->forInvoice($invoice)
            ->authorized()->withAmount(500.0)->save();

        $this->assertFalse($this->buildPaymentService()->hasPendingAuthorization($invoice->id));
    }

    public function testConfirmPaymentAdvancesToPagadaWhenAuthorizedCoverTotal(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_VERIFICACION_PAGO)->save();
        InvoicePaymentFactory::new()->forInvoice($invoice)
            ->authorized()->withAmount(1000.0)->save();
        $user = UserFactory::new()->withRequiredParents()->save();

        $result = $this->buildPaymentService()->confirmPayment($invoice->id, $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(
            InvoiceConstants::STATUS_PAGADA,
            $this->fetchTable('Invoices')->get($invoice->id)->pipeline_status,
        );
    }

    public function testConfirmPaymentFailsWhenInvoiceNotInVerificacionPago(): void
    {
        $invoice = InvoiceFactory::new()->withRequiredParents()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        InvoicePaymentFactory::new()->forInvoice($invoice)
            ->authorized()->withAmount(1000.0)->save();
        $user = UserFactory::new()->withRequiredParents()->save();

        $result = $this->buildPaymentService()->confirmPayment($invoice->id, $user->id);

        $this->assertFalse($result->success);
        $this->assertSame(
            InvoiceConstants::STATUS_TESORERIA,
            $this->fetchTable('Invoices')->get($invoice->id)->pipeline_status,
        );
    }
}

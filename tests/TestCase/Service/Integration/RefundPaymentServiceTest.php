<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Service\InvoiceHistoryService;
use App\Service\RefundHistoryService;
use App\Service\RefundPaymentService;
use App\Test\Factory\BankingEntityFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) de RefundPaymentService.
 *
 * RefundPaymentService persiste el pago como COLUMNAS en la tabla `refunds`
 * (no existe tabla `refund_payments`) y propaga las transiciones a las facturas
 * hijas (invoices con `refund_id`). Estos casos ejercitan el camino feliz del
 * subdominio de pagos releyendo siempre desde la BD para verificar la
 * persistencia y las transiciones.
 *
 * El rollback por test lo aplica la estrategia global (config/app.php →
 * TestSuite.fixtureStrategy = FactoryTransactionStrategy), por eso no se
 * declara `$fixtures`.
 */
final class RefundPaymentServiceTest extends TestCase
{
    /**
     * Construye el servicio con un stub de RBAC que siempre autoriza y un
     * EventManager aislado (sin subscribers) para que confirmPayment no
     * dispare efectos colaterales fuera del alcance del test.
     *
     * @return \App\Service\RefundPaymentService
     */
    private function buildService(): RefundPaymentService
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        return new RefundPaymentService(
            $auth,
            new RefundHistoryService(),
            new InvoiceHistoryService(),
            new EventManager(),
        );
    }

    /**
     * registerPayment válido: el monto coincide con el total de las facturas
     * hijas y avanza el reintegro a Autorización de Pago.
     *
     * @return void
     */
    public function testRegisterPaymentPersistsPaymentAndAdvancesRefund(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_TESORERIA)->save();
        InvoiceFactory::new(['refund_id' => $refund->id])
            ->withAmount(600.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        InvoiceFactory::new(['refund_id' => $refund->id])
            ->withAmount(400.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        $bank = BankingEntityFactory::new()->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->registerPayment($refund->id, [
            'banking_entity_id' => $bank->id,
            'payment_amount' => 1000.0,
            'payment_date' => '2026-06-01',
        ], $user->id, $user->role_id);

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('Refunds')->get($refund->id);
        $this->assertSame(RefundConstants::STATUS_AUTORIZACION_PAGO, $persisted->status);
        $this->assertSame($bank->id, $persisted->banking_entity_id);
        $this->assertEqualsWithDelta(1000.0, (float)$persisted->payment_amount, 0.001);
    }

    /**
     * authorizePayment: mueve el reintegro a Verificación de Pago y las
     * facturas hijas a verificacion_pago con payment_status Pago total.
     *
     * @return void
     */
    public function testAuthorizePaymentAdvancesRefundAndChildren(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_TESORERIA)->save();
        $invoice = InvoiceFactory::new(['refund_id' => $refund->id])
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        $bank = BankingEntityFactory::new()->save();
        $user = UserFactory::new()->save();
        $service = $this->buildService();

        $register = $service->registerPayment($refund->id, [
            'banking_entity_id' => $bank->id,
            'payment_amount' => 1000.0,
            'payment_date' => '2026-06-01',
        ], $user->id, $user->role_id);
        $this->assertTrue($register->success);

        $result = $service->authorizePayment($refund->id, $user->id, $user->role_id);

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('Refunds')->get($refund->id);
        $this->assertSame(RefundConstants::STATUS_VERIFICACION_PAGO, $persisted->status);
        $this->assertSame(InvoiceConstants::PAYMENT_FULL, $persisted->payment_status);

        $child = $this->fetchTable('Invoices')->get($invoice->id);
        $this->assertSame(InvoiceConstants::STATUS_VERIFICACION_PAGO, $child->pipeline_status);
        $this->assertSame(InvoiceConstants::PAYMENT_FULL, $child->payment_status);

        $payments = $this->fetchTable('InvoicePayments');
        $payment = $payments->find()->where(['invoice_id' => $invoice->id])->firstOrFail();
        $this->assertSame(InvoiceConstants::PAYMENT_RECORD_AUTHORIZED, $payment->status);
    }

    /**
     * rejectPayment: devuelve el reintegro a Tesorería y persiste el motivo
     * de rechazo en payment_rejection_reason.
     *
     * @return void
     */
    public function testRejectPaymentReturnsToTreasuryAndPersistsReason(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_TESORERIA)->save();
        InvoiceFactory::new(['refund_id' => $refund->id])
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        $bank = BankingEntityFactory::new()->save();
        $user = UserFactory::new()->save();
        $service = $this->buildService();

        $register = $service->registerPayment($refund->id, [
            'banking_entity_id' => $bank->id,
            'payment_amount' => 1000.0,
            'payment_date' => '2026-06-01',
        ], $user->id, $user->role_id);
        $this->assertTrue($register->success);

        $reason = 'Soporte de pago ilegible';
        $result = $service->rejectPayment($refund->id, $user->id, $reason, $user->role_id);

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('Refunds')->get($refund->id);
        $this->assertSame(RefundConstants::STATUS_TESORERIA, $persisted->status);
        $this->assertSame($reason, $persisted->payment_rejection_reason);
        $this->assertNull($persisted->banking_entity_id);
    }

    /**
     * confirmPayment: avanza el reintegro y sus facturas hijas de
     * verificacion_pago a pagada.
     *
     * @return void
     */
    public function testConfirmPaymentMarksRefundAndChildrenPaid(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_TESORERIA)->save();
        $invoice = InvoiceFactory::new(['refund_id' => $refund->id])
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        $bank = BankingEntityFactory::new()->save();
        $user = UserFactory::new()->save();
        $service = $this->buildService();

        $service->registerPayment($refund->id, [
            'banking_entity_id' => $bank->id,
            'payment_amount' => 1000.0,
            'payment_date' => '2026-06-01',
        ], $user->id, $user->role_id);
        $service->authorizePayment($refund->id, $user->id, $user->role_id);

        $result = $service->confirmPayment($refund->id, $user->id);

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('Refunds')->get($refund->id);
        $this->assertSame(RefundConstants::STATUS_PAGADA, $persisted->status);

        $child = $this->fetchTable('Invoices')->get($invoice->id);
        $this->assertSame(InvoiceConstants::STATUS_PAGADA, $child->pipeline_status);
    }
}

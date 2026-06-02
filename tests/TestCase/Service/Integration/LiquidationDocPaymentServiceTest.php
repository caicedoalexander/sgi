<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\NoveltyConstants;
use App\Service\LiquidationDocPaymentService;
use App\Service\NoveltyHistoryService;
use App\Test\Factory\BankingEntityFactory;
use App\Test\Factory\EmployeeNoveltyFactory;
use App\Test\Factory\LiquidationDocPaymentFactory;
use App\Test\Factory\NoveltyLiquidationDocFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) de LiquidationDocPaymentService.
 *
 * Cubre el ciclo del pago de un documento de liquidación de novedades:
 * registro → autorización → confirmación, además del rechazo. Cada caso
 * verifica la persistencia en liquidation_doc_payments y la propagación del
 * estado al doc (novelty_liquidation_docs) y a las novedades hijas
 * (employee_novelties). El rollback por test lo aplica la estrategia global
 * (FactoryTransactionStrategy).
 */
final class LiquidationDocPaymentServiceTest extends TestCase
{
    /**
     * Construye el servicio bajo prueba con un NoveltyHistoryService real
     * (asienta en novelty_histories de la BD de test).
     *
     * @return \App\Service\LiquidationDocPaymentService
     */
    private function buildService(): LiquidationDocPaymentService
    {
        return new LiquidationDocPaymentService(new NoveltyHistoryService());
    }

    public function testRegisterPaymentPersistsPaymentAndAdvancesDocAndChildren(): void
    {
        $doc = NoveltyLiquidationDocFactory::new()
            ->withStatus(NoveltyConstants::STATUS_TESORERIA)->save();
        $novelty = EmployeeNoveltyFactory::new()
            ->withStatus(NoveltyConstants::STATUS_TESORERIA)
            ->forLiquidationDoc($doc)->save();
        $bank = BankingEntityFactory::new()->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->registerPayment($doc->id, [
            'amount' => 500,
            'payment_date' => '2026-06-01',
            'banking_entity_id' => $bank->id,
        ], $user->id);

        $this->assertTrue($result->success);

        $payments = $this->fetchTable('LiquidationDocPayments');
        $payment = $payments->find()->where(['liquidation_doc_id' => $doc->id])->firstOrFail();
        $this->assertSame(NoveltyConstants::PAYMENT_RECORD_PENDING, $payment->status);
        $this->assertEqualsWithDelta(500.0, (float)$payment->amount, 0.001);

        $this->assertSame(
            NoveltyConstants::STATUS_AUTORIZACION_PAGO,
            $this->fetchTable('NoveltyLiquidationDocs')->get($doc->id)->pipeline_status,
        );
        $this->assertSame(
            NoveltyConstants::STATUS_AUTORIZACION_PAGO,
            $this->fetchTable('EmployeeNovelties')->get($novelty->id)->pipeline_status,
        );
    }

    public function testRegisterPaymentRejectsWhenPendingPaymentAlreadyExists(): void
    {
        $doc = NoveltyLiquidationDocFactory::new()
            ->withStatus(NoveltyConstants::STATUS_TESORERIA)->save();
        $bank = BankingEntityFactory::new()->save();
        $user = UserFactory::new()->save();
        LiquidationDocPaymentFactory::new()->forDoc($doc)->save();

        $result = $this->buildService()->registerPayment($doc->id, [
            'amount' => 500,
            'payment_date' => '2026-06-01',
            'banking_entity_id' => $bank->id,
        ], $user->id);

        $this->assertFalse($result->success);
        $this->assertSame(['Ya existe un pago pendiente de autorización.'], $result->errors);
        // No se creó un segundo pago.
        $this->assertSame(1, $this->fetchTable('LiquidationDocPayments')->find()->count());
    }

    public function testAuthorizePaymentMarksAuthorizedAndAdvancesToVerificacionPago(): void
    {
        $doc = NoveltyLiquidationDocFactory::new()
            ->withStatus(NoveltyConstants::STATUS_AUTORIZACION_PAGO)->save();
        $novelty = EmployeeNoveltyFactory::new()
            ->withStatus(NoveltyConstants::STATUS_AUTORIZACION_PAGO)
            ->forLiquidationDoc($doc)->save();
        $payment = LiquidationDocPaymentFactory::new()->forDoc($doc)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->authorizePayment($payment->id, $user->id);

        $this->assertTrue($result->success);

        $reloaded = $this->fetchTable('LiquidationDocPayments')->get($payment->id);
        $this->assertSame(NoveltyConstants::PAYMENT_RECORD_AUTHORIZED, $reloaded->status);
        $this->assertTrue((bool)$reloaded->authorized);
        $this->assertSame($user->id, $reloaded->authorized_by);

        $this->assertSame(
            NoveltyConstants::STATUS_VERIFICACION_PAGO,
            $this->fetchTable('NoveltyLiquidationDocs')->get($doc->id)->pipeline_status,
        );
        $this->assertSame(
            NoveltyConstants::STATUS_VERIFICACION_PAGO,
            $this->fetchTable('EmployeeNovelties')->get($novelty->id)->pipeline_status,
        );
    }

    public function testRejectPaymentMarksRejectedAndReturnsToTesoreria(): void
    {
        $doc = NoveltyLiquidationDocFactory::new()
            ->withStatus(NoveltyConstants::STATUS_AUTORIZACION_PAGO)->save();
        $novelty = EmployeeNoveltyFactory::new()
            ->withStatus(NoveltyConstants::STATUS_AUTORIZACION_PAGO)
            ->forLiquidationDoc($doc)->save();
        $payment = LiquidationDocPaymentFactory::new()->forDoc($doc)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->rejectPayment($payment->id, $user->id, 'Cuenta incorrecta');

        $this->assertTrue($result->success);

        // El pago no se elimina: persiste con status=rejected y el motivo.
        $reloaded = $this->fetchTable('LiquidationDocPayments')->get($payment->id);
        $this->assertSame(NoveltyConstants::PAYMENT_RECORD_REJECTED, $reloaded->status);
        $this->assertSame('Cuenta incorrecta', $reloaded->rejection_reason);

        $this->assertSame(
            NoveltyConstants::STATUS_TESORERIA,
            $this->fetchTable('NoveltyLiquidationDocs')->get($doc->id)->pipeline_status,
        );
        $this->assertSame(
            NoveltyConstants::STATUS_TESORERIA,
            $this->fetchTable('EmployeeNovelties')->get($novelty->id)->pipeline_status,
        );
    }

    public function testConfirmPaymentAdvancesDocAndChildrenToPagada(): void
    {
        $doc = NoveltyLiquidationDocFactory::new()
            ->withStatus(NoveltyConstants::STATUS_VERIFICACION_PAGO)->save();
        $novelty = EmployeeNoveltyFactory::new()
            ->withStatus(NoveltyConstants::STATUS_VERIFICACION_PAGO)
            ->forLiquidationDoc($doc)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->confirmPayment($doc->id, $user->id);

        $this->assertTrue($result->success);

        $this->assertSame(
            NoveltyConstants::STATUS_PAGADA,
            $this->fetchTable('NoveltyLiquidationDocs')->get($doc->id)->pipeline_status,
        );
        $this->assertSame(
            NoveltyConstants::STATUS_PAGADA,
            $this->fetchTable('EmployeeNovelties')->get($novelty->id)->pipeline_status,
        );
    }
}

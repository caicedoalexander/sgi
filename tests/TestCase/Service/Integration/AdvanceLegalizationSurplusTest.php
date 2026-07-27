<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationDocumentService;
use App\Service\AdvanceLegalizationHistoryService;
use App\Service\AdvanceLegalizationService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\BankingEntityFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) del caso SOBRANTE de AdvanceLegalizationService.
 *
 * Flujo cubierto: contabilidad --registerSurplus--> tesoreria
 * --registerRefundPayment--> autorizacion_pago --closeOnRefundAuthorized-->
 * verificacion_pago --confirmRefundExecuted--> legalizada. Y la rama de rechazo
 * reopenAfterRefundRejected: autorizacion_pago --> tesoreria.
 *
 * Los métodos del foco NO dependen de firmas ni documentos: solo de status +
 * case_type + montos, por eso cada caso siembra la legalización directamente en
 * Contabilidad (o encadena los métodos reales) en lugar de simular el InvoicePayment
 * a mano. El EventManager se construye aislado (sin subscribers) para que el cierre
 * a `legalizada` no dispare efectos colaterales fuera del alcance del test.
 *
 * El rollback por test lo aplica la estrategia global (config/app.php →
 * TestSuite.fixtureStrategy), por eso no se declara `$fixtures`.
 */
final class AdvanceLegalizationSurplusTest extends TestCase
{
    /** Payload de causación válido para las salidas del paso Contabilidad. */
    private const ACCOUNTING = [
        'accrued' => true,
        'accrual_date' => '2026-06-23',
        'ready_for_payment' => 'Si',
    ];

    /**
     * Construye el servicio con un EventManager aislado y los history/document
     * services reales. El stateRegistry queda en su default.
     *
     * @return \App\Service\AdvanceLegalizationService
     */
    private function buildService(): AdvanceLegalizationService
    {
        return new AdvanceLegalizationService(
            new EventManager(),
            new AdvanceLegalizationHistoryService(),
            new AdvanceLegalizationDocumentService(),
        );
    }

    /**
     * registerSurplus (happy path): legalización en Contabilidad con case_type
     * null declara un sobrante; releída desde BD queda en case_type='sobrante',
     * surplus_amount = monto declarado y status = Tesorería.
     *
     * @return void
     */
    public function testRegisterSurplusDeclaresSurplusAndMovesToTesoreria(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->registerSurplus($leg, 250.0, self::ACCOUNTING, $user->id);

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::CASE_SOBRANTE, $persisted->case_type);
        $this->assertSame(250.0, (float)$persisted->surplus_amount);
        $this->assertSame(AdvanceConstants::STATUS_TESORERIA, $persisted->status);
    }

    /**
     * registerSurplus persiste los 3 campos de causación y deja una fila de
     * historial por cada uno. Si el service leyera el `oldValue` DESPUÉS de
     * mutar la entidad, recordFieldChange() los descartaría en silencio por
     * old === new y estas 3 filas no existirían.
     */
    public function testRegisterSurplusPersistsAccountingAndAuditsEachField(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->registerSurplus($leg, 250.0, self::ACCOUNTING, $user->id);
        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertTrue((bool)$persisted->accrued);
        $this->assertSame('2026-06-23', $persisted->accrual_date->format('Y-m-d'));
        $this->assertSame('Si', $persisted->ready_for_payment);

        $audited = $this->fetchTable('AdvanceLegalizationHistories')->find()
            ->where(['legalization_id' => $leg->id])
            ->all()
            ->extract('field_changed')
            ->toList();
        $this->assertContains('accrued', $audited);
        $this->assertContains('accrual_date', $audited);
        $this->assertContains('ready_for_payment', $audited);
    }

    /**
     * registerSurplus con causación incompleta falla contra el gate del
     * ContabilidadState y no mueve la legalización.
     */
    public function testRegisterSurplusFailsWhenAccountingIsIncomplete(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $incomplete = ['accrued' => true, 'accrual_date' => null, 'ready_for_payment' => 'Si'];
        $result = $this->buildService()->registerSurplus($leg, 250.0, $incomplete, $user->id);

        $this->assertFalse($result->success);
        $this->assertSame('Fecha de Causación es requerida', $result->firstError());

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $persisted->status);
        $this->assertNull($persisted->case_type);
    }

    /**
     * registerSurplus con accrual_date en un formato que strtotime() no puede
     * parsear (p. ej. lo que un usuario tipea si flatpickr no cargó) debe fallar
     * contra el gate del ContabilidadState en lugar de persistir 1970-01-01
     * en silencio (strtotime devuelve false, el cast (int) lo vuelve 0).
     */
    public function testRegisterSurplusFailsWhenAccrualDateIsUnparseable(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $unparseable = ['accrued' => true, 'accrual_date' => '23/06/2026', 'ready_for_payment' => 'Si'];
        $result = $this->buildService()->registerSurplus($leg, 250.0, $unparseable, $user->id);

        $this->assertFalse($result->success);
        $this->assertSame('Fecha de Causación es requerida', $result->firstError());

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $persisted->status);
        $this->assertNull($persisted->case_type);
    }

    /**
     * registerRefundPayment (happy path): tras declarar el sobrante (Tesorería,
     * case_type='sobrante', surplus_amount>0), crea una fila en invoice_payments
     * (is_refund=true, status pending, invoice_id=advance_invoice_id,
     * amount=surplus_amount), avanza la legalización a Autorización de pago y
     * fija surplus_payment_id. Se releen ambos desde BD.
     *
     * @return void
     */
    public function testRegisterRefundPaymentCreatesPaymentAndMovesToAutorizacionPago(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();
        $service = $this->buildService();

        $surplus = $service->registerSurplus($leg, 300.0, self::ACCOUNTING, $user->id);
        $this->assertTrue($surplus->success);

        $bank = BankingEntityFactory::new()->save();
        $result = $service->registerRefundPayment(
            $leg,
            ['banking_entity_id' => $bank->id, 'payment_date' => '2026-06-02'],
            $user->id,
        );

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_AUTORIZACION_PAGO, $persisted->status);
        $this->assertNotNull($persisted->surplus_payment_id);

        $payment = $this->fetchTable('InvoicePayments')->get($persisted->surplus_payment_id);
        $this->assertTrue((bool)$payment->is_refund);
        $this->assertSame(InvoiceConstants::PAYMENT_RECORD_PENDING, $payment->status);
        $this->assertSame($anticipo->id, $payment->invoice_id);
        $this->assertSame(300.0, (float)$payment->amount);
    }

    /**
     * Flujo completo encadenado: registerSurplus → registerRefundPayment →
     * closeOnRefundAuthorized(surplus_payment_id) deja la legalización en
     * Verificación de pago → confirmRefundExecuted la cierra en `legalizada`
     * con legalized_at fijado. Cada paso se valida releyendo desde BD.
     *
     * @return void
     */
    public function testFullSurplusFlowReachesLegalizada(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();
        $service = $this->buildService();

        $bank = BankingEntityFactory::new()->save();
        $this->assertTrue($service->registerSurplus($leg, 200.0, self::ACCOUNTING, $user->id)->success);
        $this->assertTrue($service->registerRefundPayment($leg, ['banking_entity_id' => $bank->id], $user->id)->success);

        $paymentId = (int)$this->fetchTable('AdvanceLegalizations')->get($leg->id)->surplus_payment_id;

        $closed = $service->closeOnRefundAuthorized($paymentId, $user->id);
        $this->assertTrue($closed->success);

        $afterClose = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_VERIFICACION_PAGO, $afterClose->status);

        $confirmed = $service->confirmRefundExecuted($afterClose, $user->id);
        $this->assertTrue($confirmed->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_LEGALIZADA, $persisted->status);
        $this->assertNotNull($persisted->legalized_at);
    }

    /**
     * reopenAfterRefundRejected: tras registrar el reintegro (Autorización de
     * pago), rechazar el pago devuelve la legalización a Tesorería y limpia
     * surplus_payment_id (releído desde BD).
     *
     * @return void
     */
    public function testReopenAfterRefundRejectedReturnsToTesoreria(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();
        $service = $this->buildService();

        $bank = BankingEntityFactory::new()->save();
        $this->assertTrue($service->registerSurplus($leg, 150.0, self::ACCOUNTING, $user->id)->success);
        $this->assertTrue($service->registerRefundPayment($leg, ['banking_entity_id' => $bank->id], $user->id)->success);

        $paymentId = (int)$this->fetchTable('AdvanceLegalizations')->get($leg->id)->surplus_payment_id;

        $result = $service->reopenAfterRefundRejected($paymentId, $user->id);
        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_TESORERIA, $persisted->status);
        $this->assertNull($persisted->surplus_payment_id);
    }
}

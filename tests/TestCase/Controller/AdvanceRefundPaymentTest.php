<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\User;
use App\Service\AdvanceLegalizationDocumentService;
use App\Service\AdvanceLegalizationHistoryService;
use App\Service\AdvanceLegalizationService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\BankingEntityFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\InvoicePaymentFactory;
use App\Test\Factory\ProviderFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\Event\EventManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * En la legalización de un anticipo, caso "sobrante", cuando Tesorería
 * registra el reintegro al beneficiario (`AdvanceLegalizationService::
 * registerRefundPayment()`) se crea un `InvoicePayment` con `is_refund=true`
 * sobre la factura del Anticipo, que por diseño queda en
 * `pipeline_status='pagada'` (el reintegro es un movimiento posterior que
 * vive solo en la legalización).
 *
 * El botón "Autorizar"/"Rechazar" del reintegro se dibuja con
 * `AdvanceLegalizationActionPolicy::canAuthorizeRefundPayment()` y postea a
 * `AdvanceRefundPaymentsController::authorizePayment()`/`rejectPayment()`
 * (rutas `/advances/authorize-refund-payment/...` y
 * `/advances/reject-refund-payment/...`), que gatean con ese mismo policy —
 * el pipeline `legalizations`, no `invoices`. `InvoicePaymentService::
 * authorizePayment()`/`rejectPayment()` ya soportaban `is_refund` (disparan
 * `InvoiceRefundAuthorizedEvent`/`InvoiceRefundRejectedEvent` →
 * `RefundOutcomeSubscriber` → avanza/reabre la legalización); el bug era el
 * gate, no el motor.
 */
class AdvanceRefundPaymentTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableRetainFlashMessages();
    }

    /**
     * Rol con permisos de sobra: CRUD completo de `advances` + `can_operate`
     * en TODOS los pasos del pipeline `legalizations` + `invoices/autorizacion_pago`.
     * Descarta por completo que el bug sea un problema de permisos.
     */
    private function _seedFullyPermissionedUser(): User
    {
        $role = RoleFactory::new()->save();

        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => true,
        ]));

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        foreach (PipelineStepConstants::STEPS_BY_PIPELINE[PipelineStepConstants::PIPELINE_LEGALIZATIONS] as $step) {
            $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
                'role_id' => $role->id,
                'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
                'step' => $step,
                'can_operate' => true,
            ]));
        }
        $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
            'role_id' => $role->id,
            'pipeline' => PipelineStepConstants::PIPELINE_INVOICES,
            'step' => InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            'can_operate' => true,
        ]));

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    /**
     * Rol con CRUD completo de `advances` + `can_operate` en TODOS los pasos
     * del pipeline `legalizations`, pero SIN ninguna fila de
     * `pipeline_permissions` para el pipeline `invoices`. Prueba que el gate
     * del reintegro es el de `legalizations`, no el de `invoices`.
     */
    private function _seedLegalizationsOnlyPermissionedUser(): User
    {
        $role = RoleFactory::new()->save();

        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => true,
        ]));

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        foreach (PipelineStepConstants::STEPS_BY_PIPELINE[PipelineStepConstants::PIPELINE_LEGALIZATIONS] as $step) {
            $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
                'role_id' => $role->id,
                'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
                'step' => $step,
                'can_operate' => true,
            ]));
        }

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    /**
     * @param \App\Model\Entity\User|null $user Usuario a autenticar; por defecto uno con permisos completos.
     * @return array{0: \App\Model\Entity\User, 1: int, 2: int, 3: int} [user, anticipoId, legId, paymentId]
     */
    private function _seedSobranteWithRefundRegistered(?User $user = null): array
    {
        $user ??= $this->_seedFullyPermissionedUser();

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_TESORERIA)
            ->withCaseType(AdvanceConstants::CASE_SOBRANTE)
            ->withSurplusAmount(200000.0)
            ->save();

        $bank = BankingEntityFactory::new()->save();

        // Registro directo por servicio (no vía HTTP): cada `$this->post()` bootea
        // una Application nueva y `Application::events()` reengancha los subscribers
        // al `EventManager::instance()` estático en cada boot. Con dos POSTs reales
        // en el mismo test method (éste + la acción bajo prueba), el segundo evento
        // de outcome se dispararía dos veces. El registro del reintegro no es lo que
        // se está probando aquí, así que se hace en directo — mismo patrón que
        // `AdvanceLegalizationSurplusTest::buildService()`.
        $service = new AdvanceLegalizationService(
            new EventManager(),
            new AdvanceLegalizationHistoryService(),
            new AdvanceLegalizationDocumentService(),
        );
        $registered = $service->registerRefundPayment(
            $leg,
            ['banking_entity_id' => $bank->id, 'payment_date' => '2026-07-09'],
            (int)$user->id,
        );
        $this->assertTrue($registered->success, 'Setup inválido: no se pudo registrar el reintegro.');

        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $reloadedLeg = $legTable->get($leg->id);
        $this->assertSame(
            AdvanceConstants::STATUS_AUTORIZACION_PAGO,
            $reloadedLeg->status,
            'Setup inválido: el reintegro no dejó la legalización en autorizacion_pago.',
        );

        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $payment = $paymentsTable->find()
            ->where(['invoice_id' => $anticipo->id, 'is_refund' => true])
            ->firstOrFail();
        $this->assertSame(
            InvoiceConstants::PAYMENT_RECORD_PENDING,
            $payment->status,
            'Setup inválido: el InvoicePayment del reintegro no quedó pending.',
        );

        return [$user, (int)$anticipo->id, (int)$reloadedLeg->id, (int)$payment->id];
    }

    /**
     * DESEADO: un rol con permisos completos debe poder autorizar el pago de
     * reintegro.
     */
    public function testAuthorizingRefundPaymentSucceedsForRoleWithFullPermissions(): void
    {
        [$user, $anticipoId, $legId, $paymentId] = $this->_seedSobranteWithRefundRegistered();

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/authorize-refund-payment/' . $anticipoId . '/' . $paymentId);

        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $reloadedPayment = $paymentsTable->get($paymentId);
        $this->assertSame(
            InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
            $reloadedPayment->status,
            'El pago de reintegro debería quedar autorizado.',
        );
        $this->assertTrue((bool)$reloadedPayment->authorized);

        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $reloadedLeg = $legTable->get($legId);
        $this->assertSame(
            AdvanceConstants::STATUS_VERIFICACION_PAGO,
            $reloadedLeg->status,
            'La legalización debería avanzar a verificacion_pago tras autorizar el reintegro.',
        );
    }

    /**
     * DESEADO: un rol con permisos completos debe poder rechazar el pago de
     * reintegro.
     */
    public function testRejectingRefundPaymentSucceedsForRoleWithFullPermissions(): void
    {
        [$user, $anticipoId, $legId, $paymentId] = $this->_seedSobranteWithRefundRegistered();

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/reject-refund-payment/' . $anticipoId . '/' . $paymentId, [
            'reason' => 'Datos bancarios incorrectos.',
        ]);

        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $reloadedPayment = $paymentsTable->get($paymentId);
        $this->assertSame(
            InvoiceConstants::PAYMENT_RECORD_REJECTED,
            $reloadedPayment->status,
            'El pago de reintegro debería quedar rechazado.',
        );

        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $reloadedLeg = $legTable->get($legId);
        $this->assertSame(
            AdvanceConstants::STATUS_TESORERIA,
            $reloadedLeg->status,
            'La legalización debería volver a tesoreria tras rechazar el reintegro.',
        );
    }

    /**
     * El gate del reintegro es el del pipeline `legalizations`, no el de `invoices`:
     * un rol que opera todos los pasos de `legalizations` pero NINGUNO de `invoices`
     * debe poder autorizar el reintegro.
     */
    public function testAuthorizingRefundPaymentWorksWithoutInvoicesPipelinePermissions(): void
    {
        $user = $this->_seedLegalizationsOnlyPermissionedUser();
        [, $anticipoId, $legId, $paymentId] = $this->_seedSobranteWithRefundRegistered($user);

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/authorize-refund-payment/' . $anticipoId . '/' . $paymentId);

        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $reloadedPayment = $paymentsTable->get($paymentId);
        $this->assertSame(
            InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
            $reloadedPayment->status,
            'El pago de reintegro debería quedar autorizado sin permisos en el pipeline invoices.',
        );

        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $reloadedLeg = $legTable->get($legId);
        $this->assertSame(
            AdvanceConstants::STATUS_VERIFICACION_PAGO,
            $reloadedLeg->status,
            'La legalización debería avanzar a verificacion_pago tras autorizar el reintegro.',
        );
    }

    /**
     * El pago a autorizar debe ser el de ESTA legalización. Sin esta guarda, un
     * usuario con permiso sobre su propia legalización en `autorizacion_pago`
     * podría autorizar el pago de una factura ajena pasando su id en la ruta
     * (IDOR): el gate valida la legalización, pero el service opera sobre el
     * paymentId crudo.
     */
    public function testCannotAuthorizeAPaymentThatBelongsToAnotherInvoice(): void
    {
        [$user, $anticipoId, $legId] = $this->_seedSobranteWithRefundRegistered();

        $provider = ProviderFactory::new()->save();
        $foreignInvoice = InvoiceFactory::new(['provider_id' => $provider->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)
            ->save();
        $foreignPayment = InvoicePaymentFactory::new()->forInvoice($foreignInvoice)->save();

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/authorize-refund-payment/' . $anticipoId . '/' . $foreignPayment->id);

        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $reloadedForeignPayment = $paymentsTable->get($foreignPayment->id);
        $this->assertSame(
            InvoiceConstants::PAYMENT_RECORD_PENDING,
            $reloadedForeignPayment->status,
            'El pago de una factura ajena NO debería quedar autorizado (IDOR).',
        );
        $this->assertFalse((bool)$reloadedForeignPayment->authorized);

        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $reloadedLeg = $legTable->get($legId);
        $this->assertSame(
            AdvanceConstants::STATUS_AUTORIZACION_PAGO,
            $reloadedLeg->status,
            'La legalización propia no debería avanzar por un pago ajeno.',
        );

        $this->assertFlashMessage('El pago no corresponde a esta legalización.');
    }

    /**
     * Equivalente de `testCannotAuthorizeAPaymentThatBelongsToAnotherInvoice()`
     * para `rejectPayment()`.
     */
    public function testCannotRejectAPaymentThatBelongsToAnotherInvoice(): void
    {
        [$user, $anticipoId, $legId] = $this->_seedSobranteWithRefundRegistered();

        $provider = ProviderFactory::new()->save();
        $foreignInvoice = InvoiceFactory::new(['provider_id' => $provider->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)
            ->save();
        $foreignPayment = InvoicePaymentFactory::new()->forInvoice($foreignInvoice)->save();

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/reject-refund-payment/' . $anticipoId . '/' . $foreignPayment->id, [
            'reason' => 'Datos bancarios incorrectos.',
        ]);

        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $reloadedForeignPayment = $paymentsTable->get($foreignPayment->id);
        $this->assertSame(
            InvoiceConstants::PAYMENT_RECORD_PENDING,
            $reloadedForeignPayment->status,
            'El pago de una factura ajena NO debería quedar rechazado (IDOR).',
        );

        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $reloadedLeg = $legTable->get($legId);
        $this->assertSame(
            AdvanceConstants::STATUS_AUTORIZACION_PAGO,
            $reloadedLeg->status,
            'La legalización propia no debería cambiar de estado por un pago ajeno.',
        );

        $this->assertFlashMessage('El pago no corresponde a esta legalización.');
    }

    /**
     * Tesorería confirma la ejecución del reintegro y la legalización se cierra
     * en `legalizada`. Caracteriza el comportamiento antes de mudar la acción
     * de AdvancesController a este controller.
     */
    public function testConfirmingRefundPaymentClosesLegalization(): void
    {
        [$user, $anticipoId, $legId, $paymentId] = $this->_seedSobranteWithRefundRegistered();

        // Deja la legalización en `verificacion_pago` con el reintegro autorizado,
        // por el service directo (mismo motivo que _seedSobranteWithRefundRegistered:
        // evitar el doble-dispatch de eventos entre este setup y el POST bajo prueba).
        $service = new AdvanceLegalizationService(
            new EventManager(),
            new AdvanceLegalizationHistoryService(),
            new AdvanceLegalizationDocumentService(),
        );
        $closed = $service->closeOnRefundAuthorized($paymentId, (int)$user->id);
        $this->assertTrue($closed->success, 'Setup inválido: no se pudo cerrar autorizacion_pago -> verificacion_pago.');

        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $reloadedLeg = $legTable->get($legId);
        $this->assertSame(
            AdvanceConstants::STATUS_VERIFICACION_PAGO,
            $reloadedLeg->status,
            'Setup inválido: la legalización no quedó en verificacion_pago.',
        );

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/confirm-refund-payment/' . $anticipoId);

        $afterConfirm = $legTable->get($legId);
        $this->assertSame(
            AdvanceConstants::STATUS_LEGALIZADA,
            $afterConfirm->status,
            'La legalización debería quedar cerrada en legalizada tras confirmar el reintegro.',
        );
    }
}

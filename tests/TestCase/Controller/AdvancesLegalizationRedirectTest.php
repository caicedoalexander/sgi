<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\User;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\BankingEntityFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * El destino tras cada transición: avanzar/regresar devuelve a la bandeja,
 * cerrar lleva al hub de consulta, fallar deja al usuario donde estaba.
 */
class AdvancesLegalizationRedirectTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        // assertFlashMessage() lee de _requestSession, que solo recibe el flash
        // re-inyectado si retain está activo (ver EmployeesDocumentUploadTest:20).
        $this->enableRetainFlashMessages();
    }

    private function _seedOperator(string $step): User
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
        $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
            'role_id' => $role->id,
            'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            'step' => $step,
            'can_operate' => true,
        ]));

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    /**
     * Caso exacto: cierra la legalización → hub de consulta del anticipo.
     */
    public function testMarkExactRedirectsToView(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/mark-exact/' . $anticipo->id, [
            'expected_status' => AdvanceConstants::STATUS_CONTABILIDAD,
            'accrued' => '1',
            'accrual_date' => '2026-07-09',
            'ready_for_payment' => InvoiceConstants::READY_FOR_PAYMENT_SI,
        ]);

        $this->assertRedirect('/advances/view/' . $anticipo->id);
    }

    /**
     * Mueve de paso: vuelve a la bandeja, que es donde el rol elige el siguiente.
     */
    public function testMoveToAprobacionRedirectsToPendingLegalization(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_VALIDACION);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $signatures = $this->fetchTable('AdvanceLegalizationSignatures');
        $signatures->saveOrFail($signatures->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/relacion.pdf',
            'file_name' => 'relacion.pdf',
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]));

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/move-to-aprobacion/' . $anticipo->id);

        $this->assertRedirect('/advances/pending-legalization');
    }

    /**
     * Un rol sin permiso de pipeline sobre `contabilidad` no puede cerrar la
     * legalización: el gate `_denyAction()` lo rechaza y el estado no cambia.
     * Verifica que la Task 4 (ocultar controles) no sustituyó al gate del POST.
     */
    public function testMarkExactDeniedForRoleWithoutPermission(): void
    {
        // Opera `tesoreria`, no `contabilidad`.
        $user = $this->_seedOperator(AdvanceConstants::STATUS_TESORERIA);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/mark-exact/' . $anticipo->id, [
            'expected_status' => AdvanceConstants::STATUS_CONTABILIDAD,
            'accrued' => '1',
            'accrual_date' => '2026-07-09',
            'ready_for_payment' => InvoiceConstants::READY_FOR_PAYMENT_SI,
        ]);

        $this->assertRedirect('/advances/legalization/' . $anticipo->id);
        $this->assertFlashMessage('No tienes permiso para esta acción en el estado actual.');

        $reloaded = TableRegistry::getTableLocator()->get('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $reloaded->status);
    }

    /**
     * Si la transición falla, el usuario NO se va a la bandeja: se queda en la
     * vista para leer el flash de error sin perder el contexto.
     */
    public function testFailedTransitionStaysOnLegalizationView(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_VALIDACION);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        // Sin facturas vinculadas ni relación adjunta → moveToAprobacion falla.
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/move-to-aprobacion/' . $anticipo->id);

        $this->assertRedirect('/advances/legalization/' . $anticipo->id);
        // El camino de _denyAction() redirige a la MISMA URL. La aserción exacta
        // sobre el flash de ValidacionState (primer error: sin facturas vinculadas)
        // distingue "falló la validación del service" de "lo denegó el policy":
        // si el policy hubiera denegado, el flash sería el mensaje de permiso y
        // esta aserción fallaría.
        $this->assertFlashMessage('Vincule al menos una factura antes de avanzar.');
    }

    /**
     * `registerRefund` es la única acción que avanza sin cerrar (`tesoreria` →
     * `autorizacion_pago`) y la única que NO pasa por `_setStatus`: muta
     * `$leg->status` directo dentro del closure transaccional de
     * `registerRefundPayment()`. Este test blinda que esa mutación sea visible
     * fuera del closure — si no lo fuera, el helper leería `tesoreria` y
     * redirigiría a `view` por error.
     */
    public function testRegisterRefundRedirectsToPendingLegalization(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_TESORERIA);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_TESORERIA)
            ->withCaseType(AdvanceConstants::CASE_SOBRANTE)
            ->withSurplusAmount(200000.0)
            ->save();

        $bank = BankingEntityFactory::new()->save();

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/register-refund/' . $anticipo->id, [
            'banking_entity_id' => $bank->id,
            'payment_date' => '2026-07-09',
        ]);

        $this->assertRedirect('/advances/pending-legalization');

        // Avanza, no cierra: la legalización queda en Autorización de pago.
        $reloaded = TableRegistry::getTableLocator()->get('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_AUTORIZACION_PAGO, $reloaded->status);
    }

    /**
     * `confirmShortage` responde JSON a las peticiones AJAX y el JS navega al
     * destino que le indique. Como esa acción cierra la legalización, el JSON
     * debe traer la URL del hub de consulta, no dejar que el JS recargue una
     * vista de trabajo que ya no se puede operar.
     */
    public function testConfirmShortageJsonCarriesRedirectToView(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_TESORERIA);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_TESORERIA)
            ->withCaseType(AdvanceConstants::CASE_FALTANTE)
            ->withShortageAmount(250000.0)->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/confirm-shortage/' . $anticipo->id, [
            'receipt_number' => 'REC-123',
        ]);

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame('/advances/view/' . $anticipo->id, $body['redirect']);
    }
}

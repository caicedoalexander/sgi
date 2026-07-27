<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\User;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Smoke test de render de la vista operativa de legalización tras extraer los
 * parciales _linked_invoices y _soportes: GET /advances/legalization/{id}
 * responde 200 (no 500 por un error en un element). Requiere advances.can_view;
 * el anticipo lleva provider (InvoiceFactory no lo asocia por defecto).
 */
class AdvancesLegalizationRenderTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Siembra un rol con `advances.can_edit` (la vista de trabajo lo exige, igual
     * que las otras 5 vistas de trabajo del proyecto) y con permiso de pipeline
     * sobre `$step`, para que los controles de ese paso se rendericen.
     */
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

    public function testOperativeViewRenders(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_VALIDACION);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $signatures = $this->fetchTable('AdvanceLegalizationSignatures');
        $signatures->saveOrFail($signatures->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/relacion-facturas.pdf',
            'file_name' => 'relacion-facturas.pdf',
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]));

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Facturas vinculadas');
        $this->assertResponseContains('Soportes');
    }

    /**
     * La tabla de facturas vinculadas expone el root con data-parent-field para
     * SpiGroupedInvoices, y una hija Recibo de Caja (exenta de DIAN) rinde su
     * celda DIAN como "No aplica".
     */
    public function testLinkedInvoicesRenderGroupedDianCells(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_VALIDACION);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        // Hija Recibo de Caja vinculada: exenta de DIAN → celda "No aplica".
        InvoiceFactory::new(['provider_id' => $provider->id, 'advance_id' => $anticipo->id])
            ->reciboDeCaja()->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('data-parent-field="advance_id"');
        $this->assertResponseContains('No aplica');
    }

    /**
     * La columna "Tipo" de la tabla de facturas vinculadas expone el
     * `document_type` de cada hija (p. ej. Recibo de Caja).
     */
    public function testLinkedInvoicesRenderDocumentTypeColumn(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_VALIDACION);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        // Hija Recibo de Caja: su document_type aparece en la nueva columna Tipo.
        InvoiceFactory::new(['provider_id' => $provider->id, 'advance_id' => $anticipo->id])
            ->reciboDeCaja()->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('<span>Tipo</span>');
        $this->assertResponseContains(InvoiceConstants::DOCTYPE_RECIBO_CAJA);
    }

    /**
     * Regresión: `_buildLegalizationViewModel()` debe containear InvoiceDocuments
     * en la query de `$linkedInvoices`, igual que el hub `view()`. Sin ese contain,
     * `InvoicePresentation::forGroupedRow()` ve `invoice_documents` vacío
     * (`docsCount = 0`) y pinta el badge de Soporte como "Falta" aunque la hija
     * SÍ tenga un documento subido — precisamente en la vista operativa que la
     * feature existe para servir.
     */
    public function testLinkedInvoiceWithSupportDoesNotShowMissingBadge(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_VALIDACION);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        // Hija Legalización: NO exenta de soporte (DocumentTypePolicyFactory::requiresSupportFor).
        $hija = InvoiceFactory::new(['provider_id' => $provider->id, 'advance_id' => $anticipo->id])
            ->legalizacion()->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceDocumentFactory::new(['invoice_id' => $hija->id])->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        // "Falta" es literal único de la rama "missing" de grouped_cells/_support.php.
        $this->assertResponseNotContains('Falta');
    }

    /**
     * El input del monto del sobrante se precarga con el valor CRUDO. Si se
     * precargara con number_format(..., ',', '.') → "336.500", AutoNumeric lo
     * interpretaría como el número JS 336.5 y lo enviaría como 337.
     */
    public function testSurplusAmountInputRendersRawValue(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(2000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(2336500.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('name="surplus_amount"');
        $this->assertResponseContains('value="336500"');
        $this->assertResponseNotContains('value="336.500"');
    }

    /**
     * En el paso Contabilidad, el card de acción muestra los 3 campos de
     * causación junto al monto, y NO el card read-only.
     */
    public function testContabilidadRendersAccountingFields(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(2000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(2336500.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('name="accrued"');
        $this->assertResponseContains('name="accrual_date"');
        $this->assertResponseContains('name="ready_for_payment"');
        $this->assertResponseContains('Marcar como causada');
        $this->assertResponseContains('Fecha de Causación');
        $this->assertResponseContains('Lista para Pago');
    }

    /**
     * Fuera de Contabilidad y con causación registrada, la vista muestra el card
     * read-only en lugar de los inputs.
     */
    public function testTesoreriaRendersReadOnlyAccountingCard(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_TESORERIA);
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(2000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_TESORERIA)
            ->withCaseType(AdvanceConstants::CASE_FALTANTE)
            ->withShortageAmount(336500.0)
            ->withAccounting(true, '2026-06-23', InvoiceConstants::READY_FOR_PAYMENT_SI)
            ->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Causación');
        $this->assertResponseContains('23/06/2026');
        $this->assertResponseNotContains('name="accrual_date"');
    }

    /**
     * La vista de trabajo exige `advances.can_edit`, como las otras 5 del
     * proyecto. Un rol de solo consulta recibe 403, no la pantalla de trabajo.
     */
    public function testViewOnlyRoleIsForbidden(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => false,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseCode(403);
    }

    /**
     * Los 10 flags rol-aware se pueblan desde el policy, no se quedan en su
     * default `false`. Y no vienen todos del mismo predicado: con la legalización
     * en `contabilidad` y diff=0, `canMarkExact` es true mientras que
     * `canMoveToAprobacion` (que exige `validacion`) es false.
     */
    public function testFactoryPopulatesFlagsFromPolicy(): void
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
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();

        /** @var \App\ViewModel\AdvanceLegalizationViewModel $vm */
        $vm = $this->viewVariable('viewModel');

        // El rol opera `contabilidad` y la legalización está ahí: puede operar.
        $this->assertTrue($vm->canOperateCurrentStep);
        // `canMarkExact` solo exige `contabilidad` + `case_type = null`; no mira el
        // diff. El diff decide qué rama del formulario dibuja el template (Task 4).
        // Los tres predicados de `contabilidad` con `case_type = null` son true.
        // Asertarlos los tres cierra el hueco: los `assertFalse` de abajo pasarían
        // igual aunque la fábrica olvidara el flag, porque el default del VM es false.
        $this->assertTrue($vm->canMarkExact);
        $this->assertTrue($vm->canRegisterShortage);
        $this->assertTrue($vm->canRegisterSurplus);
        // Pero NO puede hacer cosas de otros pasos: esto descarta que los flags
        // vengan todos del mismo predicado.
        $this->assertFalse($vm->canMoveToAprobacion);
        $this->assertFalse($vm->canMarkSigned);
        $this->assertFalse($vm->canConfirmShortage);
        $this->assertFalse($vm->canLinkInvoices);
    }

    /**
     * Un rol con can_edit pero sin permiso de pipeline sobre el paso actual ve la
     * vista completa en modo consulta: banner presente, formularios ausentes.
     */
    public function testRoleWithoutPipelinePermissionSeesReadonlyBanner(): void
    {
        // Opera `tesoreria`, pero la legalización está en `contabilidad`.
        $user = $this->_seedOperator(AdvanceConstants::STATUS_TESORERIA);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(2000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(2000000.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Solo lectura');
        // La aserción que atrapa la regresión real: el formulario no se dibuja.
        $this->assertResponseNotContains('name="accrued"');
        $this->assertResponseNotContains('name="accrual_date"');
    }

    /**
     * Una legalización cerrada muestra su banner de cierre, no el de solo lectura.
     */
    public function testClosedLegalizationDoesNotShowReadonlyBanner(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_LEGALIZADA)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Legalizada');
        $this->assertResponseNotContains('Solo lectura');
    }

    /**
     * En un paso operable, la card Soportes muestra el botón Subir y el modal de
     * subida; nunca el bloque especial "Comprobante de consignación".
     */
    public function testSoportesCardShowsUploadWhenOperable(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Soportes');
        $this->assertResponseContains('id="uploadLegDocModal"');
        $this->assertResponseContains('spi-document-uploader');
        // Captura el bug A1: el uploader necesita init(), no solo el <script src>.
        $this->assertResponseContains('SpiDocumentUploader.init');
        $this->assertResponseNotContains('Comprobante de consignación');
    }

    /**
     * En estado terminal (legalizada) la card Soportes es read-only: sin modal de
     * subida ni botón.
     */
    public function testSoportesCardReadOnlyWhenLegalizada(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_LEGALIZADA)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Soportes');
        $this->assertResponseNotContains('id="uploadLegDocModal"');
    }

    /**
     * El hub de consulta (view) muestra los soportes de la legalización en
     * read-only: aparece el archivo, pero no el modal de subida.
     */
    public function testHubViewShowsSupportsReadOnly(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $docs = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments');
        $docs->saveOrFail($docs->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/advances/' . $leg->id . '/leg_hub.pdf',
            'file_name' => 'soporte-hub.pdf',
            'mime_type' => 'application/pdf',
        ]));

        $this->session(['Auth' => $user]);
        $this->get('/advances/view/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Soportes');
        $this->assertResponseContains('soporte-hub.pdf');
        $this->assertResponseNotContains('id="uploadLegDocModal"');
    }
}

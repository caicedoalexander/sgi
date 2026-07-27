<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Service\AdvanceLegalizationService;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\Guard\InvoiceGuard;
use App\Service\Pipeline\Invoice\InvoicePipelineStateRegistry;
use App\Service\Pipeline\Invoice\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\InvoiceFieldAccessPolicy;
use App\Service\Pipeline\Invoice\Policy\InvoiceTransitionValidator;
use App\Service\Pipeline\Invoice\Policy\LegalizacionDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\StandardDocumentTypePolicy;
use App\Service\Pipeline\Invoice\State\AprobacionState;
use App\Service\Pipeline\Invoice\State\AutorizacionPagoState;
use App\Service\Pipeline\Invoice\State\ContabilidadState;
use App\Service\Pipeline\Invoice\State\LegalizadaState;
use App\Service\Pipeline\Invoice\State\PagadaState;
use App\Service\Pipeline\Invoice\State\TesoreriaState;
use App\Service\Pipeline\Invoice\State\VerificacionPagoState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para InvoiceTransitionValidator — orquestación de validación de avance
 * y filtrado de errores por rol.
 *
 * Cubre:
 *  - validateAdvance(): rechazo bloquea todo, estado de origen inválido y bloqueo por
 *    DocumentTypePolicy (Legalización en contabilidad) — los 3 con key reservada —
 *    y aplicación de overrides.
 *  - filterErrorsForRole(): errores KEYED por requisito; key con responsable=[]
 *    gobernada por canOperate del status; key con campo responsable gobernada por los
 *    editables del rol; keys reservadas siempre visibles.
 *
 * Suite pura (sin DB): registry, factory y fieldPolicy REALES (in-memory);
 * AuthorizationFacade mockeado para controlar canOperate/operableSteps; InvoiceGuard
 * stubbeado (el real consultaría `invoice_documents`).
 */
final class InvoiceTransitionValidatorTest extends TestCase
{
    private function buildValidator(AuthorizationFacade $auth): InvoiceTransitionValidator
    {
        $payment = $this->createStub(InvoicePaymentService::class);

        $guard = $this->createStub(InvoiceGuard::class);
        $guard->method('hasAnyDocument')->willReturn(true);

        $registry = new InvoicePipelineStateRegistry(
            new AprobacionState($guard),
            new ContabilidadState(),
            new TesoreriaState($payment),
            new AutorizacionPagoState($payment),
            new VerificacionPagoState(),
            new PagadaState(),
            new LegalizadaState(),
        );

        $factory = new DocumentTypePolicyFactory(
            new StandardDocumentTypePolicy(),
            new AnticipoDocumentTypePolicy($this->createStub(AdvanceLegalizationService::class)),
            new LegalizacionDocumentTypePolicy(),
        );

        $fieldPolicy = new InvoiceFieldAccessPolicy($auth);

        return new InvoiceTransitionValidator($registry, $factory, $fieldPolicy, $auth);
    }

    private function invoice(string $status, ?string $documentType = InvoiceConstants::DOCTYPE_FACTURA, ?string $approval = null): Invoice
    {
        return new Invoice([
            'id' => 1,
            'pipeline_status' => $status,
            'document_type' => $documentType,
            'area_approval' => $approval,
        ]);
    }

    // --- validateAdvance ---

    public function testValidateAdvanceBlocksRejectedInvoice(): void
    {
        $validator = $this->buildValidator($this->createStub(AuthorizationFacade::class));
        $invoice = $this->invoice(InvoiceConstants::STATUS_APROBACION, approval: InvoiceConstants::APPROVAL_REJECTED);

        $errors = $validator->validateAdvance($invoice, InvoiceConstants::STATUS_APROBACION);

        $this->assertSame(['_rejected' => 'La factura fue rechazada. El flujo ha terminado.'], $errors);
    }

    public function testValidateAdvanceRejectsInvalidFromStatus(): void
    {
        $validator = $this->buildValidator($this->createStub(AuthorizationFacade::class));
        $invoice = $this->invoice('estado_inexistente');

        $errors = $validator->validateAdvance($invoice, 'estado_inexistente');

        $this->assertArrayHasKey('_invalid_status', $errors);
        $this->assertStringContainsString('Estado de origen inválido', $errors['_invalid_status']);
    }

    public function testValidateAdvanceBlockedByLegalizacionPolicyInContabilidad(): void
    {
        $validator = $this->buildValidator($this->createStub(AuthorizationFacade::class));
        $invoice = $this->invoice(InvoiceConstants::STATUS_CONTABILIDAD, InvoiceConstants::DOCTYPE_LEGALIZACION);

        $errors = $validator->validateAdvance($invoice, InvoiceConstants::STATUS_CONTABILIDAD);

        $this->assertArrayHasKey('_doctype_block', $errors);
        $this->assertStringContainsString('automáticamente', $errors['_doctype_block']);
    }

    public function testValidateAdvanceAppliesOverridesToSubject(): void
    {
        $validator = $this->buildValidator($this->createStub(AuthorizationFacade::class));
        // Factura NO rechazada en BD; el override la marca como rechazada.
        $invoice = $this->invoice(InvoiceConstants::STATUS_APROBACION, approval: InvoiceConstants::APPROVAL_PENDING);

        $errors = $validator->validateAdvance(
            $invoice,
            InvoiceConstants::STATUS_APROBACION,
            ['area_approval' => InvoiceConstants::APPROVAL_REJECTED],
        );

        $this->assertSame(['_rejected' => 'La factura fue rechazada. El flujo ha terminado.'], $errors);
        // El invoice original no se muta (overrides operan sobre un clon).
        $this->assertSame(InvoiceConstants::APPROVAL_PENDING, $invoice->area_approval);
    }

    // --- filterErrorsForRole ---

    public function testFilterErrorsForRoleWithResponsiblelessErrorGatedByStatusOperability(): void
    {
        // Requisito sin campos responsables (area_approval): se muestra solo si el rol
        // opera el status.
        $errors = ['area_approval' => 'Falta la aprobación del área.'];

        $authVisible = $this->createMock(AuthorizationFacade::class);
        $authVisible->method('canOperate')->willReturn(true);
        $shown = $this->buildValidator($authVisible)
            ->filterErrorsForRole($errors, 5, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame(['Falta la aprobación del área.'], $shown);

        $authHidden = $this->createMock(AuthorizationFacade::class);
        $authHidden->method('canOperate')->willReturn(false);
        $hidden = $this->buildValidator($authHidden)
            ->filterErrorsForRole($errors, 5, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame([], $hidden);
    }

    public function testFilterErrorsForRoleWithFieldErrorGatedByEditableFields(): void
    {
        // Requisito con campo responsable (dian_validation): se muestra solo si el campo
        // está entre los editables del rol en el status.
        $errors = ['dian_validation' => 'Falta la validación DIAN.'];

        // canOperate=true → editables de aprobacion incluyen dian_validation → se muestra.
        $authEditable = $this->createMock(AuthorizationFacade::class);
        $authEditable->method('canOperate')->willReturn(true);
        $shown = $this->buildValidator($authEditable)
            ->filterErrorsForRole($errors, 3, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame(['Falta la validación DIAN.'], $shown);

        // canOperate=false → editables vacíos → se oculta.
        $authNotEditable = $this->createMock(AuthorizationFacade::class);
        $authNotEditable->method('canOperate')->willReturn(false);
        $hidden = $this->buildValidator($authNotEditable)
            ->filterErrorsForRole($errors, 3, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame([], $hidden);
    }

    public function testFilterErrorsDoesNotMisattributeWhenOneRequirementPasses(): void
    {
        // area pasa, dian falla: el error DIAN debe seguir gobernado por dian_validation
        // (con el contrato posicional viejo se atribuía a area_approval).
        $errors = ['dian_validation' => 'Falta la validación DIAN.'];

        $authNotEditable = $this->createMock(AuthorizationFacade::class);
        $authNotEditable->method('canOperate')->willReturn(false);
        $hidden = $this->buildValidator($authNotEditable)
            ->filterErrorsForRole($errors, 3, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame([], $hidden);
    }

    public function testReservedKeysAlwaysPassTheFilter(): void
    {
        $errors = ['_rejected' => 'La factura fue rechazada. El flujo ha terminado.'];
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $shown = $this->buildValidator($auth)
            ->filterErrorsForRole($errors, 3, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame(['La factura fue rechazada. El flujo ha terminado.'], $shown);
    }

    public function testFilterErrorsShowsSupportDocumentWhenRoleOperatesStatus(): void
    {
        // support_document tiene responsable [] (se resuelve subiendo un documento, no
        // tecleando un campo) → su visibilidad la gobierna canOperate del status.
        $errors = ['support_document' => 'Debe cargar al menos un soporte de la factura'];

        $authVisible = $this->createMock(AuthorizationFacade::class);
        $authVisible->method('canOperate')->willReturn(true);
        $shown = $this->buildValidator($authVisible)
            ->filterErrorsForRole($errors, 5, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame(['Debe cargar al menos un soporte de la factura'], $shown);

        $authHidden = $this->createMock(AuthorizationFacade::class);
        $authHidden->method('canOperate')->willReturn(false);
        $hidden = $this->buildValidator($authHidden)
            ->filterErrorsForRole($errors, 5, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame([], $hidden);
    }
}

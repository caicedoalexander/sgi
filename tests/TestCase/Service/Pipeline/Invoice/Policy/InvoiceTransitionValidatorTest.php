<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Service\AdvanceLegalizationService;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
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
 *  - validateAdvance(): rechazo bloquea todo, estado de origen inválido, bloqueo por
 *    DocumentTypePolicy (Legalización en contabilidad) y aplicación de overrides.
 *  - getTransitionRules(): estado inválido → [].
 *  - filterErrorsForRole(): error con responsable=[] gobernado por canOperate del
 *    status; error con campo responsable gobernado por los editables del rol.
 *
 * Suite pura (sin DB): registry, factory y fieldPolicy REALES (in-memory);
 * AuthorizationFacade mockeado para controlar canOperate/operableSteps.
 */
final class InvoiceTransitionValidatorTest extends TestCase
{
    private function buildValidator(AuthorizationFacade $auth): InvoiceTransitionValidator
    {
        $payment = $this->createStub(InvoicePaymentService::class);

        $registry = new InvoicePipelineStateRegistry(
            new AprobacionState(),
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

        $this->assertSame(['La factura fue rechazada. El flujo ha terminado.'], $errors);
    }

    public function testValidateAdvanceRejectsInvalidFromStatus(): void
    {
        $validator = $this->buildValidator($this->createStub(AuthorizationFacade::class));
        $invoice = $this->invoice('estado_inexistente');

        $errors = $validator->validateAdvance($invoice, 'estado_inexistente');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Estado de origen inválido', $errors[0]);
    }

    public function testValidateAdvanceBlockedByLegalizacionPolicyInContabilidad(): void
    {
        $validator = $this->buildValidator($this->createStub(AuthorizationFacade::class));
        $invoice = $this->invoice(InvoiceConstants::STATUS_CONTABILIDAD, InvoiceConstants::DOCTYPE_LEGALIZACION);

        $errors = $validator->validateAdvance($invoice, InvoiceConstants::STATUS_CONTABILIDAD);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('automáticamente', $errors[0]);
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

        $this->assertSame(['La factura fue rechazada. El flujo ha terminado.'], $errors);
        // El invoice original no se muta (overrides operan sobre un clon).
        $this->assertSame(InvoiceConstants::APPROVAL_PENDING, $invoice->area_approval);
    }

    // --- getTransitionRules ---

    public function testGetTransitionRulesReturnsEmptyForInvalidStatus(): void
    {
        $validator = $this->buildValidator($this->createStub(AuthorizationFacade::class));

        $this->assertSame([], $validator->getTransitionRules('estado_inexistente'));
    }

    // --- filterErrorsForRole ---

    public function testFilterErrorsForRoleWithResponsiblelessErrorGatedByStatusOperability(): void
    {
        // Regla sin campos responsables (area_approval): se muestra solo si el rol
        // opera el status.
        $rules = [['field' => 'area_approval', 'label' => 'Aprobación']];
        $errors = ['Falta la aprobación del área.'];

        $authVisible = $this->createMock(AuthorizationFacade::class);
        $authVisible->method('canOperate')->willReturn(true);
        $shown = $this->buildValidator($authVisible)
            ->filterErrorsForRole($errors, $rules, 5, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame($errors, $shown);

        $authHidden = $this->createMock(AuthorizationFacade::class);
        $authHidden->method('canOperate')->willReturn(false);
        $hidden = $this->buildValidator($authHidden)
            ->filterErrorsForRole($errors, $rules, 5, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame([], $hidden);
    }

    public function testFilterErrorsForRoleWithFieldErrorGatedByEditableFields(): void
    {
        // Regla con campo responsable (dian_validation): se muestra solo si el campo
        // está entre los editables del rol en el status.
        $rules = [['field' => 'dian_validation', 'label' => 'Validación DIAN']];
        $errors = ['Falta la validación DIAN.'];

        // canOperate=true → editables de aprobacion incluyen dian_validation → se muestra.
        $authEditable = $this->createMock(AuthorizationFacade::class);
        $authEditable->method('canOperate')->willReturn(true);
        $shown = $this->buildValidator($authEditable)
            ->filterErrorsForRole($errors, $rules, 3, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame($errors, $shown);

        // canOperate=false → editables vacíos → se oculta.
        $authNotEditable = $this->createMock(AuthorizationFacade::class);
        $authNotEditable->method('canOperate')->willReturn(false);
        $hidden = $this->buildValidator($authNotEditable)
            ->filterErrorsForRole($errors, $rules, 3, InvoiceConstants::STATUS_APROBACION);
        $this->assertSame([], $hidden);
    }
}

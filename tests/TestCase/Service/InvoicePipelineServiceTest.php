<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Service\AdvanceLegalizationService;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\InvoicePaymentService;
use App\Service\InvoicePipelineService;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\InvoicePipelineStateRegistry;
use App\Service\Pipeline\Invoice\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\InvoiceFieldAccessPolicy;
use App\Service\Pipeline\Invoice\Policy\InvoiceLockPolicy;
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
use Cake\Event\EventManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para InvoicePipelineService — guards de decisión del coordinador.
 *
 * Cubre la lógica in-memory que NO toca BD:
 *  - isRejected(): predicado de rechazo de la factura.
 *  - getNextStatus()/getPreviousStatus(): avance/regresión resueltos por el
 *    enum vía States, incluyendo el bloqueo de avance de Legalización.
 *  - denialReasonForAdvance()/denialReasonForRegress(): combinan estado terminal,
 *    rechazo y autorización (AuthorizationFacade) en un DenialReason.
 *
 * Suite pura (sin DB): se construyen el StateRegistry y el DocumentTypePolicyFactory
 * REALES (States y policies son puros in-memory); el AuthorizationFacade se mockea
 * para controlar canOperate. Las policies finales (Field/Lock/TransitionValidator)
 * se construyen reales pero no se invocan en estos métodos. saveAndAdvance()/regress()
 * (transacciones con recálculo de pagos) quedan para tests de integración.
 */
final class InvoicePipelineServiceTest extends TestCase
{
    private AuthorizationFacade&MockObject $auth;

    protected function setUp(): void
    {
        $this->auth = $this->createMock(AuthorizationFacade::class);
    }

    private function buildService(): InvoicePipelineService
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

        $fieldPolicy = new InvoiceFieldAccessPolicy($this->auth);
        $lockPolicy = new InvoiceLockPolicy();
        $transitionValidator = new InvoiceTransitionValidator($registry, $factory, $fieldPolicy, $this->auth);

        return new InvoicePipelineService(
            $this->createMock(HistoryServiceInterface::class),
            $payment,
            $fieldPolicy,
            $lockPolicy,
            $transitionValidator,
            $registry,
            $factory,
            $this->createStub(EventManagerInterface::class),
            $this->auth,
        );
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

    // --- isRejected ---

    public function testIsRejectedTrueWhenApprovalRejected(): void
    {
        $service = $this->buildService();
        $invoice = $this->invoice(InvoiceConstants::STATUS_APROBACION, approval: InvoiceConstants::APPROVAL_REJECTED);

        $this->assertTrue($service->isRejected($invoice));
    }

    public function testIsRejectedFalseWhenNotRejected(): void
    {
        $service = $this->buildService();
        $invoice = $this->invoice(InvoiceConstants::STATUS_APROBACION, approval: InvoiceConstants::APPROVAL_APPROVED);

        $this->assertFalse($service->isRejected($invoice));
    }

    // --- getNextStatus ---

    public function testGetNextStatusReturnsNullForInvalidStatus(): void
    {
        $service = $this->buildService();

        $this->assertNull($service->getNextStatus('estado_inexistente'));
    }

    public function testGetNextStatusAdvancesStandardFlow(): void
    {
        $service = $this->buildService();

        $this->assertSame(
            InvoiceConstants::STATUS_CONTABILIDAD,
            $service->getNextStatus(InvoiceConstants::STATUS_APROBACION, InvoiceConstants::DOCTYPE_FACTURA),
        );
    }

    public function testGetNextStatusReturnsNullForTerminalPaid(): void
    {
        $service = $this->buildService();

        $this->assertNull($service->getNextStatus(InvoiceConstants::STATUS_PAGADA, InvoiceConstants::DOCTYPE_FACTURA));
    }

    public function testGetNextStatusBlockedForLegalizacionInContabilidad(): void
    {
        $service = $this->buildService();

        // La Legalización en contabilidad no avanza manualmente: la policy lo bloquea.
        $this->assertNull(
            $service->getNextStatus(InvoiceConstants::STATUS_CONTABILIDAD, InvoiceConstants::DOCTYPE_LEGALIZACION),
        );
    }

    public function testGetNextStatusBlockedForReciboCajaVinculadoEnContabilidad(): void
    {
        $service = $this->buildService();

        // RC vinculado (advance_id != null) en contabilidad: congelado.
        $this->assertNull(
            $service->getNextStatus(
                InvoiceConstants::STATUS_CONTABILIDAD,
                InvoiceConstants::DOCTYPE_RECIBO_CAJA,
                123,
            ),
        );
    }

    public function testGetNextStatusAllowsReciboCajaSinVincular(): void
    {
        $service = $this->buildService();

        // RC sin vincular: avanza por el pipeline normal.
        $this->assertSame(
            InvoiceConstants::STATUS_TESORERIA,
            $service->getNextStatus(
                InvoiceConstants::STATUS_CONTABILIDAD,
                InvoiceConstants::DOCTYPE_RECIBO_CAJA,
                null,
            ),
        );
    }

    // --- getPreviousStatus ---

    public function testGetPreviousStatusReturnsNullForInvalidStatus(): void
    {
        $service = $this->buildService();

        $this->assertNull($service->getPreviousStatus('estado_inexistente'));
    }

    public function testGetPreviousStatusReturnsPrevious(): void
    {
        $service = $this->buildService();

        $this->assertSame(
            InvoiceConstants::STATUS_APROBACION,
            $service->getPreviousStatus(InvoiceConstants::STATUS_CONTABILIDAD),
        );
    }

    public function testGetPreviousStatusReturnsNullForFirstStep(): void
    {
        $service = $this->buildService();

        $this->assertNull($service->getPreviousStatus(InvoiceConstants::STATUS_APROBACION));
    }

    // --- denialReasonForAdvance ---

    public function testDenialAdvanceTerminalWhenNoNextStatus(): void
    {
        $service = $this->buildService();
        $invoice = $this->invoice(InvoiceConstants::STATUS_PAGADA);

        $this->assertSame(DenialReason::TERMINAL_STATE, $service->denialReasonForAdvance($invoice, 3));
    }

    public function testDenialAdvanceRejected(): void
    {
        $service = $this->buildService();
        $invoice = $this->invoice(InvoiceConstants::STATUS_APROBACION, approval: InvoiceConstants::APPROVAL_REJECTED);

        $this->assertSame(DenialReason::REJECTED, $service->denialReasonForAdvance($invoice, 3));
    }

    public function testDenialAdvanceUnauthorized(): void
    {
        $this->auth->method('canOperate')->willReturn(false);
        $service = $this->buildService();
        $invoice = $this->invoice(InvoiceConstants::STATUS_APROBACION);

        $this->assertSame(DenialReason::UNAUTHORIZED, $service->denialReasonForAdvance($invoice, 3));
    }

    public function testDenialAdvanceNullWhenAllowed(): void
    {
        $this->auth->method('canOperate')->willReturn(true);
        $service = $this->buildService();
        $invoice = $this->invoice(InvoiceConstants::STATUS_APROBACION);

        $this->assertNull($service->denialReasonForAdvance($invoice, 3));
    }

    // --- denialReasonForRegress ---

    public function testDenialRegressTerminalWhenNoPrevious(): void
    {
        $service = $this->buildService();
        $invoice = $this->invoice(InvoiceConstants::STATUS_APROBACION);

        $this->assertSame(DenialReason::TERMINAL_STATE, $service->denialReasonForRegress($invoice, 3));
    }

    public function testDenialRegressUnauthorized(): void
    {
        $this->auth->method('canOperate')->willReturn(false);
        $service = $this->buildService();
        $invoice = $this->invoice(InvoiceConstants::STATUS_CONTABILIDAD);

        $this->assertSame(DenialReason::UNAUTHORIZED, $service->denialReasonForRegress($invoice, 3));
    }

    public function testDenialRegressNullWhenAllowed(): void
    {
        $this->auth->method('canOperate')->willReturn(true);
        $service = $this->buildService();
        $invoice = $this->invoice(InvoiceConstants::STATUS_CONTABILIDAD);

        $this->assertNull($service->denialReasonForRegress($invoice, 3));
    }
}

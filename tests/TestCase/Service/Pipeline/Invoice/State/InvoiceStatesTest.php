<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Invoice\Guard\InvoiceGuard;
use App\Service\Pipeline\Invoice\State\AprobacionState;
use App\Service\Pipeline\Invoice\State\AutorizacionPagoState;
use App\Service\Pipeline\Invoice\State\ContabilidadState;
use App\Service\Pipeline\Invoice\State\LegalizadaState;
use App\Service\Pipeline\Invoice\State\PagadaState;
use App\Service\Pipeline\Invoice\State\TesoreriaState;
use App\Service\Pipeline\Invoice\State\VerificacionPagoState;
use PHPUnit\Framework\TestCase;
use stdClass;

final class InvoiceStatesTest extends TestCase
{
    /**
     * AprobacionState con un InvoiceGuard stubbeado: la suite es pura (sin DB) y
     * el guard real consultaría `invoice_documents` vía TableRegistry.
     */
    private function aprobacionState(bool $hasDocument): AprobacionState
    {
        $guard = $this->createStub(InvoiceGuard::class);
        $guard->method('hasAnyDocument')->willReturn($hasDocument);

        return new AprobacionState($guard);
    }

    public function testAprobacionStatus(): void
    {
        $s = $this->aprobacionState(true);
        $this->assertSame(PipelineStatus::APROBACION, $s->getStatus());
        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getNextStatus());
        $this->assertNull($s->getPreviousStatus());
    }

    public function testAprobacionValidateAdvanceAllErrorsWhenInvoiceIsBlank(): void
    {
        $errors = $this->aprobacionState(true)->validateAdvance(new stdClass());
        $this->assertSame(
            [
                'area_approval' => 'Todos los aprobadores deben haber aprobado',
                'dian_validation' => 'Validación DIAN debe ser "Aprobada"',
            ],
            $errors,
        );
    }

    public function testAprobacionValidateAdvancePassesWhenApprovedAndDian(): void
    {
        $invoice = new stdClass();
        $invoice->area_approval = InvoiceConstants::APPROVAL_APPROVED;
        $invoice->dian_validation = InvoiceConstants::DIAN_APPROVED;

        $this->assertSame([], $this->aprobacionState(true)->validateAdvance($invoice));
    }

    public function testAprobacionKeysErrorsByRequirement(): void
    {
        $state = $this->aprobacionState(false);

        $invoice = new Invoice([
            'id' => 1,
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ]);

        $errors = $state->validateAdvance($invoice);
        $this->assertArrayHasKey('dian_validation', $errors);
        $this->assertArrayHasKey('support_document', $errors);
        $this->assertArrayNotHasKey('area_approval', $errors);
    }

    public function testAprobacionReciboCajaSkipsDianButNotSupport(): void
    {
        $state = $this->aprobacionState(false);

        $invoice = new Invoice([
            'id' => 1,
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ]);

        $errors = $state->validateAdvance($invoice);
        $this->assertArrayNotHasKey('dian_validation', $errors);
        $this->assertArrayHasKey('support_document', $errors);
    }

    public function testAprobacionPassesWithDianAndDocument(): void
    {
        $state = $this->aprobacionState(true);

        $invoice = new Invoice([
            'id' => 1,
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ]);

        $this->assertSame([], $state->validateAdvance($invoice));
    }

    public function testContabilidadStatus(): void
    {
        $s = new ContabilidadState();
        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getStatus());
        $this->assertSame(PipelineStatus::TESORERIA, $s->getNextStatus());
        $this->assertSame(PipelineStatus::APROBACION, $s->getPreviousStatus());
    }

    public function testContabilidadValidateAdvanceAllErrors(): void
    {
        $errors = (new ContabilidadState())->validateAdvance(new stdClass());
        $this->assertSame(
            [
                'accrued' => 'La factura debe estar marcada como Causada',
                'accrual_date' => 'Fecha de Causación es requerida',
                'ready_for_payment' => 'Campo "Lista para Pago" es requerido',
            ],
            $errors,
        );
    }

    public function testContabilidadValidateAdvancePassesWhenAllRequiredSet(): void
    {
        $invoice = new stdClass();
        $invoice->accrued = true;
        $invoice->accrual_date = '2026-05-26';
        $invoice->ready_for_payment = '1';
        $this->assertSame([], (new ContabilidadState())->validateAdvance($invoice));
    }

    public function testContabilidadValidateAdvanceTreatsFalseAndEmptyAsMissing(): void
    {
        $invoice = new stdClass();
        $invoice->accrued = false;
        $invoice->accrual_date = '';
        $invoice->ready_for_payment = false;
        $errors = (new ContabilidadState())->validateAdvance($invoice);
        $this->assertSame(
            ['accrued', 'accrual_date', 'ready_for_payment'],
            array_keys($errors),
        );
    }

    public function testTesoreriaState(): void
    {
        $payment = $this->createStub(InvoicePaymentService::class);
        $payment->method('hasPendingAuthorization')->willReturn(false);
        $state = new TesoreriaState($payment);

        $this->assertSame(PipelineStatus::TESORERIA, $state->getStatus());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, $state->getNextStatus());
        $this->assertSame(PipelineStatus::CONTABILIDAD, $state->getPreviousStatus());
    }

    public function testTesoreriaValidateAdvanceFailsWithoutPayment(): void
    {
        $payment = $this->createStub(InvoicePaymentService::class);
        $payment->method('hasPendingAuthorization')->willReturn(false);
        $state = new TesoreriaState($payment);

        $invoice = new stdClass();
        $invoice->id = 1;
        $errors = $state->validateAdvance($invoice);
        $this->assertArrayHasKey('_has_pending_payment', $errors);
        $this->assertStringContainsString('Debe registrar', $errors['_has_pending_payment']);
    }

    public function testTesoreriaValidateAdvancePassesWhenPaymentPending(): void
    {
        $payment = $this->createMock(InvoicePaymentService::class);
        $payment->expects($this->once())
            ->method('hasPendingAuthorization')
            ->with(42)
            ->willReturn(true);

        $state = new TesoreriaState($payment);
        $invoice = new stdClass();
        $invoice->id = 42;
        $this->assertSame([], $state->validateAdvance($invoice));
    }

    public function testAutorizacionPagoBlocksAdvanceWhenPaymentStillPending(): void
    {
        $payment = $this->createStub(InvoicePaymentService::class);
        $payment->method('hasPendingAuthorization')->willReturn(true);
        $state = new AutorizacionPagoState($payment);

        $invoice = new stdClass();
        $invoice->id = 7;
        $errors = $state->validateAdvance($invoice);
        $this->assertArrayHasKey('_payment_authorized', $errors);
        $this->assertStringContainsString('Contador', $errors['_payment_authorized']);
    }

    public function testAutorizacionPagoAdvancesWhenAllAuthorized(): void
    {
        $payment = $this->createStub(InvoicePaymentService::class);
        $payment->method('hasPendingAuthorization')->willReturn(false);
        $state = new AutorizacionPagoState($payment);

        $invoice = new stdClass();
        $invoice->id = 7;
        $this->assertSame([], $state->validateAdvance($invoice));
    }

    public function testVerificacionPagoIsTransitiveOnlyViaPaymentService(): void
    {
        $state = new VerificacionPagoState();
        $this->assertSame(PipelineStatus::VERIFICACION_PAGO, $state->getStatus());
        $this->assertSame(PipelineStatus::PAGADA, $state->getNextStatus());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, $state->getPreviousStatus());

        $errors = $state->validateAdvance(new stdClass());
        $this->assertArrayHasKey('_payment_executed', $errors);
        $this->assertStringContainsString('sección de pagos', $errors['_payment_executed']);
    }

    public function testPagadaIsTerminalNoPrevious(): void
    {
        $state = new PagadaState();
        $this->assertSame(PipelineStatus::PAGADA, $state->getStatus());
        $this->assertNull($state->getNextStatus());
        $this->assertNull($state->getPreviousStatus());
        $this->assertSame([], $state->validateAdvance(new stdClass()));
    }

    public function testLegalizadaIsTerminalNoPrevious(): void
    {
        $state = new LegalizadaState();
        $this->assertSame(PipelineStatus::LEGALIZADA, $state->getStatus());
        $this->assertNull($state->getNextStatus());
        $this->assertNull($state->getPreviousStatus());
        $this->assertSame([], $state->validateAdvance(new stdClass()));
    }
}

<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\InvoicePaymentService;
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
    public function testAprobacionStatus(): void
    {
        $s = new AprobacionState();
        $this->assertSame(PipelineStatus::APROBACION, $s->getStatus());
        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getNextStatus());
        $this->assertNull($s->getPreviousStatus());
    }

    public function testAprobacionValidateAdvanceAllErrorsWhenInvoiceIsBlank(): void
    {
        $errors = (new AprobacionState())->validateAdvance(new stdClass());
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('aprobadores', $errors[0]);
        $this->assertStringContainsString('DIAN', $errors[1]);
    }

    public function testAprobacionValidateAdvancePassesWhenApprovedAndDian(): void
    {
        $invoice = new stdClass();
        $invoice->area_approval = InvoiceConstants::APPROVAL_APPROVED;
        $invoice->dian_validation = InvoiceConstants::DIAN_APPROVED;

        $this->assertSame([], (new AprobacionState())->validateAdvance($invoice));
    }

    public function testAprobacionTransitionRules(): void
    {
        $rules = (new AprobacionState())->getTransitionRules();
        $fields = array_column($rules, 'field');
        $this->assertContains('area_approval', $fields);
        $this->assertContains('dian_validation', $fields);
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
        $this->assertCount(3, $errors);
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
        $this->assertCount(3, $errors);
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
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Debe registrar', $errors[0]);
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
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Contador', $errors[0]);
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
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('sección de pagos', $errors[0]);

        $rules = $state->getTransitionRules();
        $this->assertSame('_payment_executed', $rules[0]['field']);
    }

    public function testPagadaIsTerminalNoPrevious(): void
    {
        $state = new PagadaState();
        $this->assertSame(PipelineStatus::PAGADA, $state->getStatus());
        $this->assertNull($state->getNextStatus());
        $this->assertNull($state->getPreviousStatus());
        $this->assertSame([], $state->validateAdvance(new stdClass()));
        $this->assertSame([], $state->getTransitionRules());
    }

    public function testLegalizadaIsTerminalNoPrevious(): void
    {
        $state = new LegalizadaState();
        $this->assertSame(PipelineStatus::LEGALIZADA, $state->getStatus());
        $this->assertNull($state->getNextStatus());
        $this->assertNull($state->getPreviousStatus());
        $this->assertSame([], $state->validateAdvance(new stdClass()));
        $this->assertSame([], $state->getTransitionRules());
    }
}

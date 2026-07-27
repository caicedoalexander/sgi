<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\State\AgrupacionState;
use App\Service\Pipeline\Refund\State\AutorizacionPagoState;
use App\Service\Pipeline\Refund\State\ContabilidadState;
use App\Service\Pipeline\Refund\State\PagadaState;
use App\Service\Pipeline\Refund\State\TesoreriaState;
use App\Service\Pipeline\Refund\State\VerificacionPagoState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para los 6 States del pipeline de reintegros. Puros, in-memory:
 * cada State decide sus transiciones (next/previous, delegadas al enum) y los
 * requisitos de avance (validateAdvance). Sin BD ni mocks — se construyen Refund
 * reales (el constructor de Entity no aplica guarding de mass-assignment).
 *
 * Espejo de InvoiceStatesTest: cierra el gap de paridad: Refund replicaba el
 * patrón de States de Invoice sin tener su propio test de State.
 */
final class RefundStatesTest extends TestCase
{
    // --- Transiciones: contrato next/previous por estado ---

    public function testAgrupacionTransitions(): void
    {
        $s = new AgrupacionState();
        $this->assertSame(PipelineStatus::AGRUPACION, $s->getStatus());
        $this->assertSame(PipelineStatus::APROBACION, $s->getNextStatus());
        $this->assertNull($s->getPreviousStatus());
    }

    public function testContabilidadTransitions(): void
    {
        $s = new ContabilidadState();
        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getStatus());
        $this->assertSame(PipelineStatus::TESORERIA, $s->getNextStatus());
        $this->assertSame(PipelineStatus::APROBACION, $s->getPreviousStatus());
    }

    public function testTesoreriaTransitions(): void
    {
        $s = new TesoreriaState();
        $this->assertSame(PipelineStatus::TESORERIA, $s->getStatus());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, $s->getNextStatus());
        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getPreviousStatus());
    }

    public function testAutorizacionPagoTransitions(): void
    {
        $s = new AutorizacionPagoState();
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, $s->getStatus());
        $this->assertSame(PipelineStatus::VERIFICACION_PAGO, $s->getNextStatus());
        $this->assertSame(PipelineStatus::TESORERIA, $s->getPreviousStatus());
    }

    public function testVerificacionPagoTransitions(): void
    {
        $s = new VerificacionPagoState();
        $this->assertSame(PipelineStatus::VERIFICACION_PAGO, $s->getStatus());
        $this->assertSame(PipelineStatus::PAGADA, $s->getNextStatus());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, $s->getPreviousStatus());
    }

    public function testPagadaIsTerminalNoNextNoPrevious(): void
    {
        // Pagada es terminal en ambas direcciones: no admite avance ni regresión
        // (no se puede revertir un pago desde el enum). Ver PipelineStatus::previous().
        $s = new PagadaState();
        $this->assertSame(PipelineStatus::PAGADA, $s->getStatus());
        $this->assertNull($s->getNextStatus());
        $this->assertNull($s->getPreviousStatus());
    }

    // --- validateAdvance: Agrupación exige beneficiario ---

    public function testAgrupacionRequiresBeneficiaryWhenTypeMissing(): void
    {
        $errors = (new AgrupacionState())->validateAdvance(new Refund());
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('beneficiario', $errors[0]);
    }

    public function testAgrupacionRequiresEmployeeIdForEmployeeType(): void
    {
        $record = new Refund(['beneficiary_type' => RefundConstants::BENEFICIARY_TYPE_EMPLOYEE]);
        $this->assertCount(1, (new AgrupacionState())->validateAdvance($record));
    }

    public function testAgrupacionPassesForEmployeeWithId(): void
    {
        $record = new Refund([
            'beneficiary_type' => RefundConstants::BENEFICIARY_TYPE_EMPLOYEE,
            'beneficiary_employee_id' => 10,
        ]);
        $this->assertSame([], (new AgrupacionState())->validateAdvance($record));
    }

    public function testAgrupacionPassesForProviderWithId(): void
    {
        $record = new Refund([
            'beneficiary_type' => RefundConstants::BENEFICIARY_TYPE_PROVIDER,
            'beneficiary_provider_id' => 5,
        ]);
        $this->assertSame([], (new AgrupacionState())->validateAdvance($record));
    }

    // --- validateAdvance: Contabilidad exige causado + lista para pago ---

    public function testContabilidadAllErrorsWhenBlank(): void
    {
        $errors = (new ContabilidadState())->validateAdvance(new Refund());
        $this->assertCount(2, $errors);
    }

    public function testContabilidadPassesWhenAccruedAndReady(): void
    {
        $record = new Refund(['accrued' => true, 'ready_for_payment' => '1']);
        $this->assertSame([], (new ContabilidadState())->validateAdvance($record));
    }

    // --- validateAdvance: estados gestionados por la sección de pagos / terminal ---

    public function testTesoreriaAdvanceBlockedRequiresPayment(): void
    {
        $errors = (new TesoreriaState())->validateAdvance(new Refund());
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('registrar un pago', $errors[0]);
    }

    public function testAutorizacionPagoAdvanceManagedByPaymentSection(): void
    {
        $errors = (new AutorizacionPagoState())->validateAdvance(new Refund());
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('sección de pagos', $errors[0]);
    }

    public function testVerificacionPagoAdvanceManagedByPaymentSection(): void
    {
        $errors = (new VerificacionPagoState())->validateAdvance(new Refund());
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('sección de pagos', $errors[0]);
    }

    public function testPagadaAdvanceReportsTerminal(): void
    {
        $errors = (new PagadaState())->validateAdvance(new Refund());
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('estado final', $errors[0]);
    }
}

<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\PettyCash\State;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;
use App\Service\Dto\GroupReadinessReport;
use App\Service\Pipeline\PettyCash\Guard\PettyCashGuard;
use App\Service\Pipeline\PettyCash\State\AgrupacionState;
use App\Service\Pipeline\PettyCash\State\AutorizacionPagoState;
use App\Service\Pipeline\PettyCash\State\ContabilidadState;
use App\Service\Pipeline\PettyCash\State\PagadaState;
use App\Service\Pipeline\PettyCash\State\TesoreriaState;
use App\Service\Pipeline\PettyCash\State\VerificacionPagoState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para los 6 States del pipeline de caja menor. Puros, in-memory:
 * cada State decide sus transiciones (next/previous, delegadas al enum) y sus
 * requisitos de avance (validateAdvance). Sin BD ni mocks — se construyen
 * PettyCashRecord reales (el constructor de Entity no aplica guarding).
 *
 * A diferencia de Refund, la mayoría de los States de caja menor no imponen
 * requisitos a nivel de campo (validateAdvance retorna []): las invariantes de
 * agrupación (salvo el soporte documental de las hijas, vía PettyCashGuard) y
 * el avance vía pago los compone el coordinador. Solo Agrupación, Contabilidad
 * y VerificacionPago validan; Pagada NO reporta error de avance (≠ Refund).
 */
final class PettyCashStatesTest extends TestCase
{
    // --- Transiciones: contrato next/previous por estado ---

    public function testAgrupacionTransitions(): void
    {
        $s = new AgrupacionState();
        $this->assertSame(PipelineStatus::AGRUPACION, $s->getStatus());
        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getNextStatus());
        $this->assertNull($s->getPreviousStatus());
    }

    public function testContabilidadTransitions(): void
    {
        $s = new ContabilidadState();
        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getStatus());
        $this->assertSame(PipelineStatus::TESORERIA, $s->getNextStatus());
        $this->assertSame(PipelineStatus::AGRUPACION, $s->getPreviousStatus());
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

    // --- validateAdvance: Agrupación exige soporte documental de las hijas (guard mockeado) ---

    public function testAgrupacionValidateAdvanceEmptyWhenReportEmpty(): void
    {
        $guard = $this->createMock(PettyCashGuard::class);
        $guard->method('childRequirements')->willReturn(new GroupReadinessReport());

        $this->assertSame(
            [],
            (new AgrupacionState($guard))->validateAdvance(new PettyCashRecord(['id' => 1])),
        );
    }

    public function testAgrupacionValidateAdvanceReportsSupportMissing(): void
    {
        $guard = $this->createMock(PettyCashGuard::class);
        $guard->method('childRequirements')->willReturn(new GroupReadinessReport(supportMissing: [2 => 'F-2']));

        $errors = (new AgrupacionState($guard))->validateAdvance(new PettyCashRecord(['id' => 1]));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Soporte pendiente', $errors[0]);
    }

    // --- validateAdvance: estados sin requisitos de campo (los gobierna el coordinador) ---

    public function testTesoreriaHasNoFieldRequirements(): void
    {
        $this->assertSame([], (new TesoreriaState())->validateAdvance(new PettyCashRecord()));
    }

    public function testAutorizacionPagoHasNoFieldRequirements(): void
    {
        $this->assertSame([], (new AutorizacionPagoState())->validateAdvance(new PettyCashRecord()));
    }

    public function testPagadaHasNoFieldRequirements(): void
    {
        $this->assertSame([], (new PagadaState())->validateAdvance(new PettyCashRecord()));
    }

    // --- validateAdvance: Contabilidad exige causado + lista para pago ---

    public function testContabilidadAllErrorsWhenBlank(): void
    {
        $errors = (new ContabilidadState())->validateAdvance(new PettyCashRecord());
        $this->assertCount(2, $errors);
    }

    public function testContabilidadPassesWhenAccruedAndReady(): void
    {
        $record = new PettyCashRecord(['accrued' => true, 'ready_for_payment' => '1']);
        $this->assertSame([], (new ContabilidadState())->validateAdvance($record));
    }

    // --- validateAdvance: VerificacionPago se gestiona desde la sección de pagos ---

    public function testVerificacionPagoAdvanceManagedByPaymentSection(): void
    {
        $errors = (new VerificacionPagoState())->validateAdvance(new PettyCashRecord());
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('sección de pagos', $errors[0]);
    }
}

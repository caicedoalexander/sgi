<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\State\AutorizacionPagoState;
use App\Service\Pipeline\Advance\State\ContabilidadState;
use App\Service\Pipeline\Advance\State\LegalizadaState;
use App\Service\Pipeline\Advance\State\RevisionFirmasState;
use App\Service\Pipeline\Advance\State\TesoreriaState;
use App\Service\Pipeline\Advance\State\VerificacionPagoState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para los States del pipeline de legalizaciones de Anticipos
 * (ValidacionState ya tiene su propio test). Verifica transiciones y validateAdvance.
 *
 * Nota: CONTABILIDAD y TESORERIA bifurcan por case_type, por eso el enum devuelve
 * next()=null (el avance real lo resuelve el coordinador según el tipo de caso).
 * Estados puros, sin BD.
 */
final class AdvanceStatesTest extends TestCase
{
    private function leg(): AdvanceLegalization
    {
        return new AdvanceLegalization(['id' => 1]);
    }

    public function testRevisionFirmas(): void
    {
        $s = new RevisionFirmasState();

        $this->assertSame(PipelineStatus::REVISION_FIRMAS, $s->getStatus());
        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getNextStatus());
        $this->assertSame(PipelineStatus::APROBACION, $s->getPreviousStatus());
        $this->assertSame([], $s->validateAdvance($this->leg()));
    }

    public function testContabilidadBranchesSoNextIsNull(): void
    {
        $s = new ContabilidadState();

        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getStatus());
        $this->assertNull($s->getNextStatus());
        $this->assertSame(PipelineStatus::REVISION_FIRMAS, $s->getPreviousStatus());
    }

    public function testContabilidadRequiresAllThreeAccountingFields(): void
    {
        $errors = (new ContabilidadState())->validateAdvance($this->leg());

        $this->assertSame([
            'La legalización debe estar marcada como Causada',
            'Fecha de Causación es requerida',
            'Campo "Lista para Pago" es requerido',
        ], $errors);
    }

    public function testContabilidadReportsOnlyTheMissingField(): void
    {
        // Los 3 campos son non-accessible: se asignan por propiedad directa.
        $leg = $this->leg();
        $leg->accrued = true;
        $leg->accrual_date = '2026-06-23';

        $errors = (new ContabilidadState())->validateAdvance($leg);

        $this->assertSame(['Campo "Lista para Pago" es requerido'], $errors);
    }

    public function testContabilidadPassesWhenAccountingIsComplete(): void
    {
        $leg = $this->leg();
        $leg->accrued = true;
        $leg->accrual_date = '2026-06-23';
        $leg->ready_for_payment = 'Si';

        $this->assertSame([], (new ContabilidadState())->validateAdvance($leg));
    }

    public function testTesoreriaBranchesSoNextIsNull(): void
    {
        $s = new TesoreriaState();

        $this->assertSame(PipelineStatus::TESORERIA, $s->getStatus());
        $this->assertNull($s->getNextStatus());
        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getPreviousStatus());
        $this->assertSame([], $s->validateAdvance($this->leg()));
    }

    public function testAutorizacionPago(): void
    {
        $s = new AutorizacionPagoState();

        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, $s->getStatus());
        $this->assertSame(PipelineStatus::VERIFICACION_PAGO, $s->getNextStatus());
        $this->assertSame(PipelineStatus::TESORERIA, $s->getPreviousStatus());
        $this->assertSame([], $s->validateAdvance($this->leg()));
    }

    public function testVerificacionPagoIsGatedByPaymentSection(): void
    {
        $s = new VerificacionPagoState();

        $this->assertSame(PipelineStatus::VERIFICACION_PAGO, $s->getStatus());
        $this->assertSame(PipelineStatus::LEGALIZADA, $s->getNextStatus());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, $s->getPreviousStatus());

        $errors = $s->validateAdvance($this->leg());
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('sección de pago', $errors[0]);
    }

    public function testLegalizadaIsTerminal(): void
    {
        $s = new LegalizadaState();

        $this->assertSame(PipelineStatus::LEGALIZADA, $s->getStatus());
        $this->assertNull($s->getNextStatus());
        $this->assertNull($s->getPreviousStatus());
        $this->assertSame([], $s->validateAdvance($this->leg()));
    }
}

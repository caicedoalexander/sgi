<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\State\AprobacionState;
use App\Service\Pipeline\Novelty\State\AutorizacionPagoState;
use App\Service\Pipeline\Novelty\State\ContabilidadState;
use App\Service\Pipeline\Novelty\State\GdpState;
use App\Service\Pipeline\Novelty\State\PagadaState;
use App\Service\Pipeline\Novelty\State\RevisionFirmasState;
use App\Service\Pipeline\Novelty\State\RrhhState;
use App\Service\Pipeline\Novelty\State\TesoreriaState;
use App\Service\Pipeline\Novelty\State\VerificacionPagoState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para los States del pipeline de novedades.
 *
 * Cubre transiciones (enum next/previous), validateAdvanceIndividual y, para los
 * States que no dependen del NoveltyLiquidationGuard (final, con BD), también
 * validateAdvanceGroup. El validateAdvanceGroup de Contabilidad/Gdp/RevisionFirmas
 * usa el Guard → queda para integración.
 *
 * Estados puros, sin BD.
 */
final class NoveltyStatesTest extends TestCase
{
    private function novelty(array $data = []): EmployeeNovelty
    {
        return new EmployeeNovelty($data + ['id' => 1]);
    }

    private function doc(array $data = []): NoveltyLiquidationDoc
    {
        return new NoveltyLiquidationDoc($data + ['id' => 1]);
    }

    // --- Aprobacion ---

    public function testAprobacionTransitions(): void
    {
        $s = new AprobacionState();

        $this->assertSame(PipelineStatus::APROBACION, $s->getStatus());
        $this->assertSame(PipelineStatus::RRHH, $s->getNextStatus());
        $this->assertSame(PipelineStatus::REGISTRO, $s->getPreviousStatus());
    }

    public function testAprobacionRequiresApprover(): void
    {
        $errors = (new AprobacionState())->validateAdvanceIndividual($this->novelty(['approver_id' => null]));

        $this->assertContains('Debe asignar un aprobador.', $errors);
    }

    public function testAprobacionFlagsRejected(): void
    {
        $errors = (new AprobacionState())->validateAdvanceIndividual($this->novelty([
            'approver_id' => 9,
            'area_approval' => NoveltyConstants::APPROVAL_REJECTED,
        ]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('rechazada', $errors[0]);
    }

    public function testAprobacionPassesWhenApproverSetAndNotRejected(): void
    {
        $errors = (new AprobacionState())->validateAdvanceIndividual($this->novelty([
            'approver_id' => 9,
            'area_approval' => NoveltyConstants::APPROVAL_APPROVED,
        ]));

        $this->assertSame([], $errors);
    }

    public function testAprobacionGroupNotApplicable(): void
    {
        $errors = (new AprobacionState())->validateAdvanceGroup($this->doc());

        $this->assertSame(['Esta etapa no aplica a documentos de liquidación.'], $errors);
    }

    // --- Rrhh ---

    public function testRrhhTransitions(): void
    {
        $s = new RrhhState();

        $this->assertSame(PipelineStatus::RRHH, $s->getStatus());
        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getNextStatus());
        $this->assertSame(PipelineStatus::APROBACION, $s->getPreviousStatus());
    }

    public function testRrhhRequiresPayrollDecision(): void
    {
        $missing = (new RrhhState())->validateAdvanceIndividual($this->novelty(['passes_payroll' => null]));
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('Nómina', $missing[0]);

        $ok = (new RrhhState())->validateAdvanceIndividual($this->novelty(['passes_payroll' => true]));
        $this->assertSame([], $ok);
    }

    // --- Contabilidad (guard usado solo en validateAdvanceGroup, no testeado) ---

    public function testContabilidadTransitions(): void
    {
        $s = new ContabilidadState();

        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getStatus());
        $this->assertSame(PipelineStatus::REVISION_FIRMAS, $s->getNextStatus());
        $this->assertSame(PipelineStatus::RRHH, $s->getPreviousStatus());
    }

    public function testContabilidadIndividualRequiresLiquidationDoc(): void
    {
        $missing = (new ContabilidadState())->validateAdvanceIndividual($this->novelty(['liquidation_doc_id' => null]));
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('documento de liquidación', $missing[0]);

        $ok = (new ContabilidadState())->validateAdvanceIndividual($this->novelty(['liquidation_doc_id' => 7]));
        $this->assertSame([], $ok);
    }

    // --- RevisionFirmas (guard solo en group) ---

    public function testRevisionFirmasTransitions(): void
    {
        $s = new RevisionFirmasState();

        $this->assertSame(PipelineStatus::REVISION_FIRMAS, $s->getStatus());
        $this->assertSame(PipelineStatus::GDP, $s->getNextStatus());
        $this->assertSame(PipelineStatus::CONTABILIDAD, $s->getPreviousStatus());
    }

    public function testRevisionFirmasIndividualOnlyAdvancesFromGroup(): void
    {
        $errors = (new RevisionFirmasState())->validateAdvanceIndividual($this->novelty());

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('documento de liquidación grupal', $errors[0]);
    }

    // --- Gdp (guard solo en group) ---

    public function testGdpTransitions(): void
    {
        $s = new GdpState();

        $this->assertSame(PipelineStatus::GDP, $s->getStatus());
        $this->assertSame(PipelineStatus::TESORERIA, $s->getNextStatus());
        $this->assertSame(PipelineStatus::REVISION_FIRMAS, $s->getPreviousStatus());
    }

    public function testGdpIndividualOnlyAdvancesFromGroup(): void
    {
        $errors = (new GdpState())->validateAdvanceIndividual($this->novelty());

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('documento de liquidación grupal', $errors[0]);
    }

    // --- Tesoreria ---

    public function testTesoreriaTransitions(): void
    {
        $s = new TesoreriaState();

        $this->assertSame(PipelineStatus::TESORERIA, $s->getStatus());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, $s->getNextStatus());
        $this->assertSame(PipelineStatus::GDP, $s->getPreviousStatus());
    }

    public function testTesoreriaGroupRequiresPayment(): void
    {
        $errors = (new TesoreriaState())->validateAdvanceGroup($this->doc());

        $this->assertSame(['Debe registrar un pago para avanzar desde Tesorería.'], $errors);
    }

    // --- AutorizacionPago ---

    public function testAutorizacionPagoTransitions(): void
    {
        $s = new AutorizacionPagoState();

        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, $s->getStatus());
        $this->assertSame(PipelineStatus::VERIFICACION_PAGO, $s->getNextStatus());
        $this->assertSame(PipelineStatus::TESORERIA, $s->getPreviousStatus());
    }

    public function testAutorizacionPagoGroupGatedByPaymentSection(): void
    {
        $errors = (new AutorizacionPagoState())->validateAdvanceGroup($this->doc());

        $this->assertStringContainsString('sección de pagos', $errors[0]);
    }

    // --- VerificacionPago ---

    public function testVerificacionPagoTransitions(): void
    {
        $s = new VerificacionPagoState();

        $this->assertSame(PipelineStatus::VERIFICACION_PAGO, $s->getStatus());
        $this->assertSame(PipelineStatus::PAGADA, $s->getNextStatus());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, $s->getPreviousStatus());
    }

    public function testVerificacionPagoGatedByPaymentSection(): void
    {
        $this->assertStringContainsString(
            'documento de liquidación',
            (new VerificacionPagoState())->validateAdvanceIndividual($this->novelty())[0],
        );
        $this->assertStringContainsString(
            'sección de pagos',
            (new VerificacionPagoState())->validateAdvanceGroup($this->doc())[0],
        );
    }

    // --- Pagada (terminal) ---

    public function testPagadaIsTerminal(): void
    {
        $s = new PagadaState();

        $this->assertSame(PipelineStatus::PAGADA, $s->getStatus());
        $this->assertNull($s->getNextStatus());
        $this->assertNull($s->getPreviousStatus());
    }

    public function testPagadaRejectsFurtherAdvance(): void
    {
        $this->assertStringContainsString(
            'estado final',
            (new PagadaState())->validateAdvanceIndividual($this->novelty())[0],
        );
        $this->assertStringContainsString(
            'estado final',
            (new PagadaState())->validateAdvanceGroup($this->doc())[0],
        );
    }
}

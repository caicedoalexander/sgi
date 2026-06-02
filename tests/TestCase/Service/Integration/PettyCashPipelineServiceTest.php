<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Authorization\AuthorizationFacade;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\PettyCashConstants;
use App\Service\InvoiceHistoryService;
use App\Service\PettyCashPipelineService;
use App\Service\Pipeline\PettyCash\Policy\PettyCashFieldAccessPolicy;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) de PettyCashPipelineService.
 *
 * Coordinador del pipeline de Caja Menor (agrupacion → contabilidad → tesoreria →
 * autorizacion_pago → verificacion_pago → pagada). Estos casos ejercitan el avance
 * y la regresión releyendo siempre desde la BD para verificar la persistencia del
 * estado, además del cálculo de motivos de denegación (`denialReasonForAdvance`).
 *
 * El rollback por test lo aplica la estrategia global (config/app.php →
 * TestSuite.fixtureStrategy), por eso no se declara `$fixtures`.
 */
final class PettyCashPipelineServiceTest extends TestCase
{
    /**
     * Construye el servicio con un stub de RBAC que autoriza según `$canOperate`.
     * Las policies se construyen reales (alimentadas por el mismo stub) y los
     * history services reales; los parámetros opcionales quedan en sus defaults.
     *
     * @param bool $canOperate Valor que devuelve el stub de `canOperate`.
     * @return \App\Service\PettyCashPipelineService
     */
    private function buildService(bool $canOperate = true): PettyCashPipelineService
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn($canOperate);
        $auth->method('operableSteps')->willReturn([]);

        return new PettyCashPipelineService(
            new InvoiceHistoryService(),
            $auth,
            new PettyCashFieldAccessPolicy($auth),
        );
    }

    /**
     * advance() camino feliz en una transición sin requisitos de campos
     * (agrupacion → contabilidad). Solo exige ≥1 factura agrupada; tras avanzar
     * el record queda en contabilidad releyendo desde la BD.
     *
     * @return void
     */
    public function testAdvanceMovesRecordFromAgrupacionToContabilidad(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_AGRUPACION)->save();
        InvoiceFactory::new(['petty_cash_record_id' => $record->id])->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->advance($record, $user->role_id, $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(PettyCashConstants::STATUS_CONTABILIDAD, $result->data['nextStatus']);

        $persisted = $this->fetchTable('PettyCashRecords')->get($record->id);
        $this->assertSame(PettyCashConstants::STATUS_CONTABILIDAD, $persisted->status);
    }

    /**
     * regress() un paso: un record en tesoreria vuelve a contabilidad y la
     * transición se persiste en la BD junto con el estado anterior reportado.
     *
     * @return void
     */
    public function testRegressMovesRecordFromTesoreriaToContabilidad(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_TESORERIA)->save();
        InvoiceFactory::new(['petty_cash_record_id' => $record->id])->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->regress(
            $record,
            $user->role_id,
            $user->id,
            'Faltan soportes contables del registro.',
        );

        $this->assertTrue($result->success);
        $this->assertSame(PettyCashConstants::STATUS_CONTABILIDAD, $result->data['previousStatus']);

        $persisted = $this->fetchTable('PettyCashRecords')->get($record->id);
        $this->assertSame(PettyCashConstants::STATUS_CONTABILIDAD, $persisted->status);
    }

    /**
     * denialReasonForAdvance(): cuando el rol no puede operar el paso (stub con
     * canOperate=false) sobre un estado no terminal, retorna UNAUTHORIZED.
     *
     * @return void
     */
    public function testDenialReasonForAdvanceReturnsUnauthorizedWhenRoleCannotOperate(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_AGRUPACION)->save();
        $user = UserFactory::new()->save();

        $reason = $this->buildService(false)->denialReasonForAdvance($record, $user->role_id);

        $this->assertSame(DenialReason::UNAUTHORIZED, $reason);
    }

    /**
     * denialReasonForAdvance(): un record en el estado terminal `pagada` no puede
     * avanzar y retorna TERMINAL_STATE (incluso con un rol que sí autoriza).
     *
     * @return void
     */
    public function testDenialReasonForAdvanceReturnsTerminalStateOnPagada(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_PAGADA)->save();
        $user = UserFactory::new()->save();

        $reason = $this->buildService()->denialReasonForAdvance($record, $user->role_id);

        $this->assertSame(DenialReason::TERMINAL_STATE, $reason);
    }
}

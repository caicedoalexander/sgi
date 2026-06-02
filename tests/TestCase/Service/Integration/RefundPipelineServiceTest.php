<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Authorization\AuthorizationFacade;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Service\InvoiceHistoryService;
use App\Service\RefundPipelineService;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) del coordinador RefundPipelineService.
 *
 * Reintegros comparte los 6 estados de PettyCash
 * (agrupacion → contabilidad → tesoreria → autorizacion_pago →
 * verificacion_pago → pagada) y propaga las transiciones a las facturas hijas
 * (invoices con `refund_id`). Estos casos ejercitan advance/regress releyendo
 * siempre desde la BD para verificar la persistencia del estado, y los motivos
 * de denegación (UNAUTHORIZED, TERMINAL_STATE).
 *
 * El rollback por test lo aplica la estrategia global (config/app.php →
 * TestSuite.fixtureStrategy = FactoryTransactionStrategy), por eso no se
 * declara `$fixtures`.
 */
final class RefundPipelineServiceTest extends TestCase
{
    /**
     * Construye el servicio con BD real: un stub de RBAC con el resultado de
     * canOperate parametrizable y los demás colaboradores reales (defaults
     * internos para los parámetros opcionales del constructor).
     *
     * @param bool $canOperate Resultado fijo de AuthorizationFacade::canOperate.
     * @return \App\Service\RefundPipelineService
     */
    private function buildService(bool $canOperate = true): RefundPipelineService
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn($canOperate);
        $auth->method('operableSteps')->willReturn([]);

        return new RefundPipelineService(
            new InvoiceHistoryService(),
            $auth,
        );
    }

    /**
     * advance() camino feliz: un reintegro en Contabilidad con los campos
     * contables completos (causado + lista para pago) y al menos una factura
     * hija avanza a Tesorería; las facturas hijas se propagan a tesoreria.
     *
     * @return void
     */
    public function testAdvanceMovesRefundFromContabilidadToTesoreria(): void
    {
        $refund = RefundFactory::new([
            'accrued' => true,
            'ready_for_payment' => true,
        ])->withStatus(RefundConstants::STATUS_CONTABILIDAD)->save();
        $invoice = InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->advance($refund, $user->role_id, $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(RefundConstants::STATUS_TESORERIA, $result->data['nextStatus']);

        $persisted = $this->fetchTable('Refunds')->get($refund->id);
        $this->assertSame(RefundConstants::STATUS_TESORERIA, $persisted->status);

        $child = $this->fetchTable('Invoices')->get($invoice->id);
        $this->assertSame(InvoiceConstants::STATUS_TESORERIA, $child->pipeline_status);
    }

    /**
     * regress() un paso: un reintegro en Tesorería regresa a Contabilidad con
     * un motivo válido; el estado y las facturas hijas se persisten.
     *
     * @return void
     */
    public function testRegressMovesRefundFromTesoreriaToContabilidad(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_TESORERIA)->save();
        $invoice = InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        $user = UserFactory::new()->save();

        $reason = 'Faltan soportes contables en una de las facturas agrupadas';
        $result = $this->buildService()->regress($refund, $user->role_id, $user->id, $reason);

        $this->assertTrue($result->success);
        $this->assertSame(RefundConstants::STATUS_CONTABILIDAD, $result->data['previousStatus']);

        $persisted = $this->fetchTable('Refunds')->get($refund->id);
        $this->assertSame(RefundConstants::STATUS_CONTABILIDAD, $persisted->status);

        $child = $this->fetchTable('Invoices')->get($invoice->id);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $child->pipeline_status);
    }

    /**
     * denialReasonForAdvance() devuelve UNAUTHORIZED cuando el rol no puede
     * operar el paso actual (canOperate=false) en una transición sin
     * requisito de pago.
     *
     * @return void
     */
    public function testDenialReasonForAdvanceReturnsUnauthorized(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $denial = $this->buildService(canOperate: false)
            ->denialReasonForAdvance($refund, $user->role_id);

        $this->assertSame(DenialReason::UNAUTHORIZED, $denial);
    }

    /**
     * denialReasonForAdvance() devuelve TERMINAL_STATE cuando el reintegro está
     * en Pagada (sin transición forward).
     *
     * @return void
     */
    public function testDenialReasonForAdvanceReturnsTerminalStateWhenPagada(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_PAGADA)->save();
        $user = UserFactory::new()->save();

        $denial = $this->buildService()
            ->denialReasonForAdvance($refund, $user->role_id);

        $this->assertSame(DenialReason::TERMINAL_STATE, $denial);
    }
}

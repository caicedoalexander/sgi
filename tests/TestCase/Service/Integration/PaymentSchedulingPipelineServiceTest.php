<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Authorization\AuthorizationFacade;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\PaymentSchedulingConstants;
use App\Service\PaymentSchedulingPipelineService;
use App\Test\Factory\BankingEntityFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PaymentSchedulingFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) del coordinador del pipeline de programación de
 * pagos (borrador → tesoreria → autorizacion_pago → verificacion_pago → pagada).
 *
 * Se ejercitan las transiciones simples (sin materialización de pagos) releyendo
 * siempre desde la BD para verificar el `pipeline_status` persistido tras
 * advance/regress/reject, además de los predicados de denegación.
 *
 * El rollback por test lo aplica la estrategia global (config/app.php →
 * TestSuite.fixtureStrategy = FactoryTransactionStrategy), por eso no se declara
 * `$fixtures`.
 */
final class PaymentSchedulingPipelineServiceTest extends TestCase
{
    use PaymentServiceIntegrationTrait;

    /**
     * Construye el coordinador con un stub de RBAC que autoriza/deniega según
     * `$canOperate` y un InvoicePaymentService real (vía el trait compartido).
     * Los demás colaboradores usan sus defaults internos.
     *
     * @param bool $canOperate Valor que retorna el stub de `canOperate`.
     * @return \App\Service\PaymentSchedulingPipelineService
     */
    private function buildService(bool $canOperate = true): PaymentSchedulingPipelineService
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn($canOperate);
        $auth->method('operableSteps')->willReturn([]);

        return new PaymentSchedulingPipelineService(
            $this->buildPaymentService(),
            $auth,
        );
    }

    /**
     * reject(): una programación en Autorización de pago vuelve a Tesorería y el
     * nuevo estado queda persistido en BD.
     *
     * @return void
     */
    public function testRejectReturnsSchedulingToTreasury(): void
    {
        $scheduling = PaymentSchedulingFactory::new()
            ->withStatus(PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO)
            ->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->reject($scheduling, $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentSchedulingConstants::STATUS_TESORERIA, $result->data['toStatus']);

        $persisted = $this->fetchTable('PaymentSchedulings')->get($scheduling->id);
        $this->assertSame(PaymentSchedulingConstants::STATUS_TESORERIA, $persisted->pipeline_status);
    }

    /**
     * advance() happy path borrador → tesoreria. BorradorState exige al menos una
     * factura vinculada; el requisito se cumple creando el item vía linkItems().
     *
     * @return void
     */
    public function testAdvanceMovesDraftToTreasury(): void
    {
        $scheduling = PaymentSchedulingFactory::new()
            ->withStatus(PaymentSchedulingConstants::STATUS_BORRADOR)
            ->save();
        $invoice = InvoiceFactory::new()->withAmount(500.0)->save();
        $bank = BankingEntityFactory::new()->save();
        $user = UserFactory::new()->save();
        $service = $this->buildService();

        $linked = $service->linkItems($scheduling->id, [[
            'invoice_id' => $invoice->id,
            'banking_entity_id' => $bank->id,
            'amount' => 500.0,
        ]]);
        $this->assertTrue($linked->success);

        $result = $service->advance($scheduling, $user->role_id, $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentSchedulingConstants::STATUS_TESORERIA, $result->data['nextStatus']);

        $persisted = $this->fetchTable('PaymentSchedulings')->get($scheduling->id);
        $this->assertSame(PaymentSchedulingConstants::STATUS_TESORERIA, $persisted->pipeline_status);
    }

    /**
     * advance() falla cuando el borrador no tiene facturas vinculadas: el estado
     * permanece en borrador en BD.
     *
     * @return void
     */
    public function testAdvanceFailsWhenDraftHasNoLinkedItems(): void
    {
        $scheduling = PaymentSchedulingFactory::new()
            ->withStatus(PaymentSchedulingConstants::STATUS_BORRADOR)
            ->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->advance($scheduling, $user->role_id, $user->id);

        $this->assertFalse($result->success);

        $persisted = $this->fetchTable('PaymentSchedulings')->get($scheduling->id);
        $this->assertSame(PaymentSchedulingConstants::STATUS_BORRADOR, $persisted->pipeline_status);
    }

    /**
     * regress() un paso: tesoreria → borrador con motivo válido. Verifica el
     * estado persistido y el `previousStatus` retornado.
     *
     * @return void
     */
    public function testRegressMovesTreasuryBackToDraft(): void
    {
        $scheduling = PaymentSchedulingFactory::new()
            ->withStatus(PaymentSchedulingConstants::STATUS_TESORERIA)
            ->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->regress(
            $scheduling,
            $user->role_id,
            $user->id,
            'Falta documentación de soporte',
        );

        $this->assertTrue($result->success);
        $this->assertSame(PaymentSchedulingConstants::STATUS_BORRADOR, $result->data['previousStatus']);

        $persisted = $this->fetchTable('PaymentSchedulings')->get($scheduling->id);
        $this->assertSame(PaymentSchedulingConstants::STATUS_BORRADOR, $persisted->pipeline_status);
    }

    /**
     * denialReasonForAdvance retorna UNAUTHORIZED cuando el rol no puede operar el
     * paso actual (stub de RBAC con canOperate=false).
     *
     * @return void
     */
    public function testDenialReasonForAdvanceUnauthorized(): void
    {
        $scheduling = PaymentSchedulingFactory::new()
            ->withStatus(PaymentSchedulingConstants::STATUS_TESORERIA)
            ->save();
        $user = UserFactory::new()->save();

        $denial = $this->buildService(false)->denialReasonForAdvance($scheduling, $user->role_id);

        $this->assertSame(DenialReason::UNAUTHORIZED, $denial);
    }

    /**
     * denialReasonForAdvance retorna TERMINAL_STATE cuando la programación ya está
     * en el último paso (pagada), sin importar el RBAC.
     *
     * @return void
     */
    public function testDenialReasonForAdvanceTerminalState(): void
    {
        $scheduling = PaymentSchedulingFactory::new()
            ->withStatus(PaymentSchedulingConstants::STATUS_PAGADA)
            ->save();
        $user = UserFactory::new()->save();

        $denial = $this->buildService()->denialReasonForAdvance($scheduling, $user->role_id);

        $this->assertSame(DenialReason::TERMINAL_STATE, $denial);
    }
}

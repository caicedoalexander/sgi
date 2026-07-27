<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Novelty\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\Policy\NoveltyActionPolicy;
use App\ValueObject\UserContext;
use PHPUnit\Framework\TestCase;

/**
 * Verifica la composición rol×paso (vía AuthorizationFacade::canOperate) + estado
 * del agregado en NoveltyActionPolicy. Los pagos operan sobre NoveltyLiquidationDoc
 * (pipeline_status); canOperateCurrentStep opera sobre EmployeeNovelty. Puro.
 */
final class NoveltyActionPolicyTest extends TestCase
{
    private const ROLE = 3;
    private const ROLE_WITHOUT_PERMISSION = 999;

    public function testCanOperateStepDelegatesToFacadeWithNoveltiesPipeline(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with(
                $this->callback(fn(UserContext $u) => $u->roleId === self::ROLE),
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_TESORERIA,
            )
            ->willReturn(true);

        $policy = new NoveltyActionPolicy($auth);
        $this->assertTrue($policy->canOperateStep(self::ROLE, NoveltyConstants::STATUS_TESORERIA));
    }

    public function testCanOperateCurrentStepFalseWhenRejected(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $novelty = new EmployeeNovelty(['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA]);

        $policy = new NoveltyActionPolicy($auth);
        $this->assertFalse($policy->canOperateCurrentStep($novelty, self::ROLE));
    }

    public function testCanOperateCurrentStepUsesPipelineStatusAsStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), NoveltyConstants::STATUS_CONTABILIDAD)
            ->willReturn(true);

        $novelty = new EmployeeNovelty(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD]);

        $policy = new NoveltyActionPolicy($auth);
        $this->assertTrue($policy->canOperateCurrentStep($novelty, self::ROLE));
    }

    public function testCanRegisterPaymentTrueWhenTesoreriaAndRoleCanOperate(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), NoveltyConstants::STATUS_TESORERIA)
            ->willReturn(true);

        $doc = new NoveltyLiquidationDoc(['pipeline_status' => NoveltyConstants::STATUS_TESORERIA]);

        $policy = new NoveltyActionPolicy($auth);
        $this->assertTrue($policy->canRegisterPayment($doc, self::ROLE));
    }

    public function testCanRegisterPaymentFalseWhenStatusIsNotTesoreria(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $doc = new NoveltyLiquidationDoc(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD]);

        $policy = new NoveltyActionPolicy($auth);
        $this->assertFalse($policy->canRegisterPayment($doc, self::ROLE));
    }

    public function testCanRegisterPaymentFalseWhenRoleCannotOperate(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $doc = new NoveltyLiquidationDoc(['pipeline_status' => NoveltyConstants::STATUS_TESORERIA]);

        $policy = new NoveltyActionPolicy($auth);
        $this->assertFalse($policy->canRegisterPayment($doc, self::ROLE_WITHOUT_PERMISSION));
    }

    public function testCanAuthorizePaymentChecksAutorizacionPagoStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), NoveltyConstants::STATUS_AUTORIZACION_PAGO)
            ->willReturn(true);

        $doc = new NoveltyLiquidationDoc(['pipeline_status' => NoveltyConstants::STATUS_AUTORIZACION_PAGO]);

        $policy = new NoveltyActionPolicy($auth);
        $this->assertTrue($policy->canAuthorizePayment($doc, self::ROLE));
    }

    public function testCanRejectPaymentChecksAutorizacionPagoStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), NoveltyConstants::STATUS_AUTORIZACION_PAGO)
            ->willReturn(true);

        $doc = new NoveltyLiquidationDoc(['pipeline_status' => NoveltyConstants::STATUS_AUTORIZACION_PAGO]);

        $policy = new NoveltyActionPolicy($auth);
        $this->assertTrue($policy->canRejectPayment($doc, self::ROLE));
    }

    public function testCanConfirmPaymentChecksVerificacionPagoStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), NoveltyConstants::STATUS_VERIFICACION_PAGO)
            ->willReturn(true);

        $doc = new NoveltyLiquidationDoc(['pipeline_status' => NoveltyConstants::STATUS_VERIFICACION_PAGO]);

        $policy = new NoveltyActionPolicy($auth);
        $this->assertTrue($policy->canConfirmPayment($doc, self::ROLE));
    }
}

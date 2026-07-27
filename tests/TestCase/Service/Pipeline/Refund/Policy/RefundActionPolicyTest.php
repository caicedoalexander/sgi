<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Refund\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\Policy\RefundActionPolicy;
use App\ValueObject\UserContext;
use PHPUnit\Framework\TestCase;

/**
 * Verifica la composición rol×paso (vía AuthorizationFacade::canOperate) + estado
 * del agregado (Refund::canX) en RefundActionPolicy. Puro: el facade se mockea,
 * el Refund se construye real. Cada acción debe gatear con el paso correcto.
 */
final class RefundActionPolicyTest extends TestCase
{
    private const ROLE = 3;
    private const ROLE_WITHOUT_PERMISSION = 999;

    public function testCanOperateStepDelegatesToFacadeWithRefundsPipeline(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with(
                $this->callback(fn(UserContext $u) => $u->roleId === self::ROLE),
                PipelineStepConstants::PIPELINE_REFUNDS,
                RefundConstants::STATUS_CONTABILIDAD,
            )
            ->willReturn(true);

        $policy = new RefundActionPolicy($auth);
        $this->assertTrue($policy->canOperateStep(self::ROLE, RefundConstants::STATUS_CONTABILIDAD));
    }

    public function testCanOperateCurrentStepFalseWhenPagada(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $refund = new Refund(['status' => RefundConstants::STATUS_PAGADA]);

        $policy = new RefundActionPolicy($auth);
        $this->assertFalse($policy->canOperateCurrentStep($refund, self::ROLE));
    }

    public function testCanOperateCurrentStepUsesCurrentStatusAsStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), RefundConstants::STATUS_CONTABILIDAD)
            ->willReturn(true);

        $refund = new Refund(['status' => RefundConstants::STATUS_CONTABILIDAD]);

        $policy = new RefundActionPolicy($auth);
        $this->assertTrue($policy->canOperateCurrentStep($refund, self::ROLE));
    }

    public function testCanRegisterPaymentTrueWhenTesoreriaAndRoleCanOperate(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), RefundConstants::STATUS_TESORERIA)
            ->willReturn(true);

        $refund = new Refund(['status' => RefundConstants::STATUS_TESORERIA]);

        $policy = new RefundActionPolicy($auth);
        $this->assertTrue($policy->canRegisterPayment($refund, self::ROLE));
    }

    public function testCanRegisterPaymentFalseWhenStatusIsNotTesoreria(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $refund = new Refund(['status' => RefundConstants::STATUS_CONTABILIDAD]);

        $policy = new RefundActionPolicy($auth);
        $this->assertFalse($policy->canRegisterPayment($refund, self::ROLE));
    }

    public function testCanRegisterPaymentFalseWhenRoleCannotOperate(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $refund = new Refund(['status' => RefundConstants::STATUS_TESORERIA]);

        $policy = new RefundActionPolicy($auth);
        $this->assertFalse($policy->canRegisterPayment($refund, self::ROLE_WITHOUT_PERMISSION));
    }

    public function testCanAuthorizePaymentChecksAutorizacionPagoStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), RefundConstants::STATUS_AUTORIZACION_PAGO)
            ->willReturn(true);

        $refund = new Refund(['status' => RefundConstants::STATUS_AUTORIZACION_PAGO]);

        $policy = new RefundActionPolicy($auth);
        $this->assertTrue($policy->canAuthorizePayment($refund, self::ROLE));
    }

    public function testCanConfirmPaymentChecksVerificacionPagoStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), RefundConstants::STATUS_VERIFICACION_PAGO)
            ->willReturn(true);

        $refund = new Refund(['status' => RefundConstants::STATUS_VERIFICACION_PAGO]);

        $policy = new RefundActionPolicy($auth);
        $this->assertTrue($policy->canConfirmPayment($refund, self::ROLE));
    }
}

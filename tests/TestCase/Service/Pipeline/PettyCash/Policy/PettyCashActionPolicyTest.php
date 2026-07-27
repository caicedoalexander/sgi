<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\PettyCash\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\Policy\PettyCashActionPolicy;
use App\ValueObject\UserContext;
use PHPUnit\Framework\TestCase;

/**
 * Verifica la composición rol×paso (vía AuthorizationFacade::canOperate) + estado
 * del agregado (PettyCashRecord::canX) en PettyCashActionPolicy. Puro: el facade se
 * mockea, el registro se construye real. Cada acción gatea con el paso correcto.
 */
final class PettyCashActionPolicyTest extends TestCase
{
    private const ROLE = 3;
    private const ROLE_WITHOUT_PERMISSION = 999;

    public function testCanOperateStepDelegatesToFacadeWithPettyCashPipeline(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with(
                $this->callback(fn(UserContext $u) => $u->roleId === self::ROLE),
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_CONTABILIDAD,
            )
            ->willReturn(true);

        $policy = new PettyCashActionPolicy($auth);
        $this->assertTrue($policy->canOperateStep(self::ROLE, PettyCashConstants::STATUS_CONTABILIDAD));
    }

    public function testCanRegisterPaymentTrueWhenTesoreriaAndRoleCanOperate(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), PettyCashConstants::STATUS_TESORERIA)
            ->willReturn(true);

        $record = new PettyCashRecord(['status' => PettyCashConstants::STATUS_TESORERIA]);

        $policy = new PettyCashActionPolicy($auth);
        $this->assertTrue($policy->canRegisterPayment($record, self::ROLE));
    }

    public function testCanRegisterPaymentFalseWhenStatusIsNotTesoreria(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $record = new PettyCashRecord(['status' => PettyCashConstants::STATUS_CONTABILIDAD]);

        $policy = new PettyCashActionPolicy($auth);
        $this->assertFalse($policy->canRegisterPayment($record, self::ROLE));
    }

    public function testCanRegisterPaymentFalseWhenRoleCannotOperate(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $record = new PettyCashRecord(['status' => PettyCashConstants::STATUS_TESORERIA]);

        $policy = new PettyCashActionPolicy($auth);
        $this->assertFalse($policy->canRegisterPayment($record, self::ROLE_WITHOUT_PERMISSION));
    }

    public function testCanAuthorizePaymentChecksAutorizacionPagoStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), PettyCashConstants::STATUS_AUTORIZACION_PAGO)
            ->willReturn(true);

        $record = new PettyCashRecord(['status' => PettyCashConstants::STATUS_AUTORIZACION_PAGO]);

        $policy = new PettyCashActionPolicy($auth);
        $this->assertTrue($policy->canAuthorizePayment($record, self::ROLE));
    }

    public function testCanConfirmPaymentChecksVerificacionPagoStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), PettyCashConstants::STATUS_VERIFICACION_PAGO)
            ->willReturn(true);

        $record = new PettyCashRecord(['status' => PettyCashConstants::STATUS_VERIFICACION_PAGO]);

        $policy = new PettyCashActionPolicy($auth);
        $this->assertTrue($policy->canConfirmPayment($record, self::ROLE));
    }
}

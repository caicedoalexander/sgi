<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Advance\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\AdvanceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\ValueObject\UserContext;
use PHPUnit\Framework\TestCase;

/**
 * Verifica los dos métodos que alimentan la capa de vista: el flag del banner
 * de solo lectura y los pasos visibles de la bandeja.
 */
final class AdvanceLegalizationActionPolicyTest extends TestCase
{
    private const ROLE_CONTABILIDAD = 2;

    public function testCanOperateCurrentStepTrueWhenRoleOperatesTheStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with(
                $this->callback(fn(UserContext $u) => $u->roleId === self::ROLE_CONTABILIDAD),
                PipelineStepConstants::PIPELINE_LEGALIZATIONS,
                AdvanceConstants::STATUS_CONTABILIDAD,
            )
            ->willReturn(true);

        $leg = new AdvanceLegalization(['status' => AdvanceConstants::STATUS_CONTABILIDAD]);
        $policy = new AdvanceLegalizationActionPolicy($auth);

        $this->assertTrue($policy->canOperateCurrentStep($leg, self::ROLE_CONTABILIDAD));
    }

    public function testCanOperateCurrentStepFalseWhenRoleCannotOperateTheStep(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $leg = new AdvanceLegalization(['status' => AdvanceConstants::STATUS_CONTABILIDAD]);
        $policy = new AdvanceLegalizationActionPolicy($auth);

        $this->assertFalse($policy->canOperateCurrentStep($leg, self::ROLE_CONTABILIDAD));
    }

    /**
     * `legalizada` es terminal y no figura en STEPS_BY_PIPELINE. El policy corta
     * antes de consultar la facade — nadie "opera" una legalización cerrada.
     */
    public function testCanOperateCurrentStepFalseWhenLegalizationIsClosed(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->never())->method('canOperate');

        $leg = new AdvanceLegalization(['status' => AdvanceConstants::STATUS_LEGALIZADA]);
        $policy = new AdvanceLegalizationActionPolicy($auth);

        $this->assertFalse($policy->canOperateCurrentStep($leg, self::ROLE_CONTABILIDAD));
    }

    public function testGetVisibleStatusesDelegatesToOperableSteps(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('operableSteps')
            ->with(
                $this->callback(fn(UserContext $u) => $u->roleId === self::ROLE_CONTABILIDAD),
                PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            )
            ->willReturn([AdvanceConstants::STATUS_CONTABILIDAD]);

        $policy = new AdvanceLegalizationActionPolicy($auth);

        $this->assertSame(
            [AdvanceConstants::STATUS_CONTABILIDAD],
            $policy->getVisibleStatuses(self::ROLE_CONTABILIDAD),
        );
    }
}

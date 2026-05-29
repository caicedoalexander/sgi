<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Refund\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Service\Pipeline\Refund\Policy\RefundFieldAccessPolicy;
use App\ValueObject\UserContext;
use PHPUnit\Framework\TestCase;

/**
 * A1 — gemelo de PettyCashFieldAccessPolicyTest para Refund: el filtrado de
 * campos del header es rol-aware (patch vacío sin canOperate del paso),
 * preservando los casts de beneficiary y la validación inline de accrual_date.
 */
final class RefundFieldAccessPolicyTest extends TestCase
{
    private const ROLE_AGRUPACION = 5;
    private const ROLE_CONTABILIDAD = 2;

    public function testReturnsEmptyPatchWhenRoleCannotOperateAgrupacion(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $policy = new RefundFieldAccessPolicy($auth);
        $result = $policy->filterEntityData(
            [
                'beneficiary_type' => RefundConstants::BENEFICIARY_TYPE_EMPLOYEE,
                'beneficiary_employee_id' => '5',
            ],
            999,
            RefundConstants::STATUS_AGRUPACION,
        );

        $this->assertSame([], $result->patch);
        $this->assertFalse($result->hasErrors());
    }

    public function testPopulatesBeneficiaryWithCastsWhenCanOperate(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with(
                $this->callback(fn(UserContext $u): bool => $u->roleId === self::ROLE_AGRUPACION),
                PipelineStepConstants::PIPELINE_REFUNDS,
                RefundConstants::STATUS_AGRUPACION,
            )
            ->willReturn(true);

        $policy = new RefundFieldAccessPolicy($auth);
        $result = $policy->filterEntityData(
            [
                'beneficiary_type' => RefundConstants::BENEFICIARY_TYPE_EMPLOYEE,
                'beneficiary_employee_id' => '5',
            ],
            self::ROLE_AGRUPACION,
            RefundConstants::STATUS_AGRUPACION,
        );

        $this->assertFalse($result->hasErrors());
        $this->assertSame(RefundConstants::BENEFICIARY_TYPE_EMPLOYEE, $result->patch['beneficiary_type']);
        $this->assertSame(5, $result->patch['beneficiary_employee_id'], 'el id debe castearse a int, no quedar como "5"');
        $this->assertNull($result->patch['beneficiary_provider_id']);
    }

    public function testPreservesAccrualDateValidationWhenRoleCanOperate(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $policy = new RefundFieldAccessPolicy($auth);
        $result = $policy->filterEntityData(
            ['accrued' => '1', 'accrual_date' => ''],
            self::ROLE_CONTABILIDAD,
            RefundConstants::STATUS_CONTABILIDAD,
        );

        $this->assertTrue($result->hasErrors());
        $this->assertSame(
            ['La fecha de causación es requerida cuando el registro está marcado como causado.'],
            $result->errors,
        );
    }
}

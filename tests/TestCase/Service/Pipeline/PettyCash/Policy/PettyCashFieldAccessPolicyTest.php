<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\PettyCash\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Service\Pipeline\PettyCash\Policy\PettyCashFieldAccessPolicy;
use App\ValueObject\UserContext;
use PHPUnit\Framework\TestCase;

/**
 * A1 — el filtrado de campos del header es rol-aware: un rol sin canOperate del
 * paso obtiene patch vacío (no escribe ni valida), y un rol con canOperate
 * conserva el patch, las coerciones de tipo y la validación inline de accrual_date.
 */
final class PettyCashFieldAccessPolicyTest extends TestCase
{
    private const ROLE_CONTABILIDAD = 2;

    public function testReturnsEmptyPatchWhenRoleCannotOperateContabilidad(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $policy = new PettyCashFieldAccessPolicy($auth);
        $result = $policy->filterEntityData(
            ['notes' => 'x', 'accrued' => '1', 'accrual_date' => '', 'ready_for_payment' => '1'],
            999,
            PettyCashConstants::STATUS_CONTABILIDAD,
        );

        // Sin canOperate del paso: ningún campo del header se persiste y no se
        // emite el error de accrual_date (el rol no opera ni valida ese paso).
        $this->assertSame([], $result->patch);
        $this->assertFalse($result->hasErrors());
    }

    public function testPopulatesPatchAndKeepsCoercionsWhenRoleCanOperateContabilidad(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with(
                $this->callback(fn(UserContext $u): bool => $u->roleId === self::ROLE_CONTABILIDAD),
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_CONTABILIDAD,
            )
            ->willReturn(true);

        $policy = new PettyCashFieldAccessPolicy($auth);
        $result = $policy->filterEntityData(
            ['notes' => 'n', 'accrued' => '1', 'accrual_date' => '2026-01-10', 'ready_for_payment' => '1'],
            self::ROLE_CONTABILIDAD,
            PettyCashConstants::STATUS_CONTABILIDAD,
        );

        $this->assertFalse($result->hasErrors());
        $this->assertSame('n', $result->patch['notes']);
        $this->assertTrue($result->patch['accrued'], 'accrued debe normalizarse a bool, no quedar como "1"');
        $this->assertSame('2026-01-10', $result->patch['accrual_date']);
        $this->assertSame('1', $result->patch['ready_for_payment']);
    }

    public function testPreservesAccrualDateValidationWhenRoleCanOperate(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $policy = new PettyCashFieldAccessPolicy($auth);
        $result = $policy->filterEntityData(
            ['accrued' => '1', 'accrual_date' => ''],
            self::ROLE_CONTABILIDAD,
            PettyCashConstants::STATUS_CONTABILIDAD,
        );

        $this->assertTrue($result->hasErrors());
        $this->assertSame(
            ['La fecha de causación es requerida cuando el registro está marcado como causado.'],
            $result->errors,
        );
    }

    public function testAllowsNotesInAgrupacionWhenRoleCanOperate(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $policy = new PettyCashFieldAccessPolicy($auth);
        $result = $policy->filterEntityData(
            ['notes' => 'x'],
            self::ROLE_CONTABILIDAD,
            PettyCashConstants::STATUS_AGRUPACION,
        );

        $this->assertSame(['notes' => 'x'], $result->patch);
        $this->assertFalse($result->hasErrors());
    }
}

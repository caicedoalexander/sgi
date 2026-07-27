<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Dto\GroupReadinessReport;
use App\Service\Pipeline\Refund\State\AprobacionState;
use App\Service\RefundApprovalGuard;
use PHPUnit\Framework\TestCase;

class AprobacionStateTest extends TestCase
{
    public function testTransitions(): void
    {
        $state = new AprobacionState(new RefundApprovalGuard());
        $this->assertSame(PipelineStatus::APROBACION, $state->getStatus());
        $this->assertSame(PipelineStatus::CONTABILIDAD, $state->getNextStatus());
        $this->assertSame(PipelineStatus::AGRUPACION, $state->getPreviousStatus());
    }

    public function testValidateAdvanceBlocksWithoutQuorum(): void
    {
        $guard = $this->createMock(RefundApprovalGuard::class);
        $guard->method('allApproved')->willReturn(false);
        $guard->method('childRequirements')->willReturn(new GroupReadinessReport());

        $errors = (new AprobacionState($guard))->validateAdvance(new Refund(['id' => 1]));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('aprobación', mb_strtolower($errors[0]));
    }

    public function testValidateAdvanceListsDianOffenders(): void
    {
        $guard = $this->createMock(RefundApprovalGuard::class);
        $guard->method('allApproved')->willReturn(true);
        $guard->method('childRequirements')->willReturn(new GroupReadinessReport(dianPending: [1 => 'F-1']));

        $errors = (new AprobacionState($guard))->validateAdvance(new Refund(['id' => 1]));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('DIAN', $errors[0]);
        $this->assertStringContainsString('F-1', $errors[0]);
    }

    public function testValidateAdvanceListsSupportOffenders(): void
    {
        $guard = $this->createMock(RefundApprovalGuard::class);
        $guard->method('allApproved')->willReturn(true);
        $guard->method('childRequirements')->willReturn(new GroupReadinessReport(supportMissing: [2 => 'F-2']));

        $errors = (new AprobacionState($guard))->validateAdvance(new Refund(['id' => 1]));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Soporte pendiente', $errors[0]);
        $this->assertStringContainsString('F-2', $errors[0]);
    }

    public function testValidateAdvancePassesWithQuorumAndDian(): void
    {
        $guard = $this->createMock(RefundApprovalGuard::class);
        $guard->method('allApproved')->willReturn(true);
        $guard->method('childRequirements')->willReturn(new GroupReadinessReport());

        $this->assertSame([], (new AprobacionState($guard))->validateAdvance(new Refund(['id' => 1])));
    }
}

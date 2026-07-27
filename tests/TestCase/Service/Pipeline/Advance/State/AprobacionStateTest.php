<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\AdvanceLegalizationApprovalGuard;
use App\Service\Dto\GroupReadinessReport;
use App\Service\Pipeline\Advance\State\AprobacionState;
use PHPUnit\Framework\TestCase;

final class AprobacionStateTest extends TestCase
{
    public function testTransitions(): void
    {
        $state = new AprobacionState($this->createStub(AdvanceLegalizationApprovalGuard::class));
        $this->assertSame(PipelineStatus::APROBACION, $state->getStatus());
        $this->assertSame(PipelineStatus::REVISION_FIRMAS, $state->getNextStatus());
        $this->assertSame(PipelineStatus::VALIDACION, $state->getPreviousStatus());
    }

    public function testValidateAdvanceBlocksWithoutQuorum(): void
    {
        $guard = $this->createMock(AdvanceLegalizationApprovalGuard::class);
        $guard->method('allApproved')->willReturn(false);
        $guard->method('childRequirements')->willReturn(new GroupReadinessReport());

        $errors = (new AprobacionState($guard))
            ->validateAdvance(new AdvanceLegalization(['id' => 1, 'advance_invoice_id' => 5]));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('aprobación', mb_strtolower($errors[0]));
    }

    public function testValidateAdvanceListsDianOffenders(): void
    {
        $guard = $this->createMock(AdvanceLegalizationApprovalGuard::class);
        $guard->method('allApproved')->willReturn(true);
        $guard->method('childRequirements')->willReturn(new GroupReadinessReport(dianPending: [9 => 'F-9']));

        $errors = (new AprobacionState($guard))
            ->validateAdvance(new AdvanceLegalization(['id' => 1, 'advance_invoice_id' => 5]));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('DIAN', $errors[0]);
        $this->assertStringContainsString('F-9', $errors[0]);
    }

    public function testValidateAdvanceListsSupportOffenders(): void
    {
        $guard = $this->createMock(AdvanceLegalizationApprovalGuard::class);
        $guard->method('allApproved')->willReturn(true);
        $guard->method('childRequirements')->willReturn(new GroupReadinessReport(supportMissing: [9 => 'F-9']));

        $errors = (new AprobacionState($guard))
            ->validateAdvance(new AdvanceLegalization(['id' => 1, 'advance_invoice_id' => 5]));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Soporte pendiente', $errors[0]);
    }

    public function testValidateAdvancePassesWithQuorumAndDian(): void
    {
        $guard = $this->createMock(AdvanceLegalizationApprovalGuard::class);
        $guard->method('allApproved')->willReturn(true);
        $guard->method('childRequirements')->willReturn(new GroupReadinessReport());

        $this->assertSame([], (new AprobacionState($guard))
            ->validateAdvance(new AdvanceLegalization(['id' => 1, 'advance_invoice_id' => 5])));
    }
}

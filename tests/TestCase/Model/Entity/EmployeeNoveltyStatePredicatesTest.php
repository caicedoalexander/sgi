<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use PHPUnit\Framework\TestCase;

/**
 * Cubre los predicados de estado del agregado EmployeeNovelty. Puro, sin BD.
 */
final class EmployeeNoveltyStatePredicatesTest extends TestCase
{
    private function novelty(array $props): EmployeeNovelty
    {
        return new EmployeeNovelty($props);
    }

    public function testIsRejectedTrueOnlyForRechazadaStatus(): void
    {
        $this->assertTrue($this->novelty(['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA])->isRejected());
        $this->assertFalse($this->novelty(['pipeline_status' => NoveltyConstants::STATUS_TESORERIA])->isRejected());
        $this->assertFalse($this->novelty([])->isRejected());
    }

    public function testIsPaidTrueOnlyForPagadaStatus(): void
    {
        $this->assertTrue($this->novelty(['pipeline_status' => NoveltyConstants::STATUS_PAGADA])->isPaid());
        $this->assertFalse($this->novelty(['pipeline_status' => NoveltyConstants::STATUS_TESORERIA])->isPaid());
    }

    public function testIsGroupedTrueWhenLiquidationDocIdPresent(): void
    {
        $this->assertTrue($this->novelty(['liquidation_doc_id' => 42])->isGrouped());
        $this->assertFalse($this->novelty(['liquidation_doc_id' => null])->isGrouped());
        $this->assertFalse($this->novelty([])->isGrouped());
    }

    public function testIsApprovalRejectedTrueOnlyForRejectedAreaApproval(): void
    {
        $this->assertTrue($this->novelty(['area_approval' => NoveltyConstants::APPROVAL_REJECTED])->isApprovalRejected());
        $this->assertFalse($this->novelty(['area_approval' => NoveltyConstants::APPROVAL_PENDING])->isApprovalRejected());
        $this->assertFalse($this->novelty([])->isApprovalRejected());
    }
}

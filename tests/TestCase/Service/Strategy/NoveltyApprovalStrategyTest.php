<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Strategy;

use App\Constants\ApprovalConstants;
use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Service\NoveltyHistoryService;
use App\Service\NoveltyObservationService;
use App\Service\Strategy\NoveltyApprovalStrategy;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for NoveltyApprovalStrategy::apply().
 *
 * These tests run the REAL strategy (tables mocked) through both the APPROVE
 * and REJECT paths to prevent regression of the type of TypeError that was
 * hidden in InvoiceApprovalStrategy when collaborators were only mocked.
 */
final class NoveltyApprovalStrategyTest extends TestCase
{
    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testApplyApproveSetsRrhhStatusAndApprovedFields(): void
    {
        $entityId = 42;
        $createdBy = 7;
        $approverId = 3;

        $novelty = new EmployeeNovelty(['id' => $entityId, 'approver_id' => $approverId]);

        $noveltiesTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'save'])
            ->getMock();
        $noveltiesTable->method('get')->with($entityId)->willReturn($novelty);
        $noveltiesTable->method('save')->willReturn($novelty);

        $locator = new TableLocator();
        $locator->set('EmployeeNovelties', $noveltiesTable);
        TableRegistry::setTableLocator($locator);

        $historyService = $this->getMockBuilder(NoveltyHistoryService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['recordStatusChange', 'recordFieldChange'])
            ->getMock();
        $historyService->expects($this->once())
            ->method('recordStatusChange')
            ->with(
                $entityId,
                NoveltyConstants::STATUS_APROBACION,
                NoveltyConstants::STATUS_RRHH,
                $approverId,
            );
        $historyService->expects($this->once())
            ->method('recordFieldChange')
            ->with(
                $entityId,
                'approver_response',
                null,
                NoveltyConstants::APPROVAL_APPROVED,
                $approverId,
            );

        $observationService = $this->createMock(NoveltyObservationService::class);
        $observationService->expects($this->never())->method('addToNovelty');

        $strategy = new NoveltyApprovalStrategy($observationService, $historyService);
        $result = $strategy->apply($entityId, ApprovalConstants::ACTION_APPROVE, null, $createdBy, null);

        $this->assertTrue($result);
        $this->assertSame(NoveltyConstants::STATUS_RRHH, $novelty->pipeline_status);
        $this->assertSame(NoveltyConstants::APPROVAL_APPROVED, $novelty->area_approval);
        $this->assertSame($approverId, $novelty->approved_by);
        $this->assertNotNull($novelty->approved_at);
    }

    public function testApplyRejectSetsRejectedApproval(): void
    {
        $entityId = 55;
        $createdBy = 9;
        $approverId = 4;

        $novelty = new EmployeeNovelty(['id' => $entityId, 'approver_id' => $approverId]);

        $noveltiesTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'save'])
            ->getMock();
        $noveltiesTable->method('get')->with($entityId)->willReturn($novelty);
        $noveltiesTable->method('save')->willReturn($novelty);

        $locator = new TableLocator();
        $locator->set('EmployeeNovelties', $noveltiesTable);
        TableRegistry::setTableLocator($locator);

        $historyService = $this->getMockBuilder(NoveltyHistoryService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['recordStatusChange', 'recordFieldChange'])
            ->getMock();
        $historyService->expects($this->never())->method('recordStatusChange');
        $historyService->expects($this->once())
            ->method('recordFieldChange')
            ->with(
                $entityId,
                'approver_response',
                null,
                NoveltyConstants::APPROVAL_REJECTED,
                $approverId,
            );

        $observationService = $this->createMock(NoveltyObservationService::class);
        $observationService->expects($this->never())->method('addToNovelty');

        $strategy = new NoveltyApprovalStrategy($observationService, $historyService);
        $result = $strategy->apply($entityId, ApprovalConstants::ACTION_REJECT, null, $createdBy, null);

        $this->assertTrue($result);
        $this->assertSame(NoveltyConstants::APPROVAL_REJECTED, $novelty->area_approval);
        $this->assertSame($approverId, $novelty->approved_by);
        $this->assertNotNull($novelty->approved_at);
    }
}

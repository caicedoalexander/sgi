<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Strategy;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Service\InvoiceHistoryService;
use App\Service\InvoicePipelineService;
use App\Service\ServiceResult;
use App\Service\Strategy\InvoiceApprovalStrategy;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Regresión: apply(APPROVE) debe invocar InvoicePipelineService::saveAndAdvance
 * con (int $roleId, int $userId) — NO con el nombre de rol (string) en la posición
 * del userId. El refactor del pipeline quitó el antiguo parámetro $roleName; un
 * mismatch aquí provoca TypeError en producción (oculto porque el flujo externo
 * mockea la Strategy).
 */
final class InvoiceApprovalStrategyTest extends TestCase
{
    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testApplyApproveCallsSaveAndAdvanceWithIntUserId(): void
    {
        $invoice = new Invoice(['id' => 5, 'pipeline_status' => 'aprobacion']);

        $invoicesTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
        $invoicesTable->method('get')->willReturn($invoice);

        $roleQuery = $this->getMockBuilder(SelectQuery::class)
            ->disableOriginalConstructor()->onlyMethods(['where', 'firstOrFail'])->getMock();
        $roleQuery->method('where')->willReturnSelf();
        $roleQuery->method('firstOrFail')->willReturn(new Entity(['id' => 99]));
        $rolesTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()->onlyMethods(['find'])->getMock();
        $rolesTable->method('find')->willReturn($roleQuery);

        $locator = new TableLocator();
        $locator->set('Invoices', $invoicesTable);
        $locator->set('Roles', $rolesTable);
        TableRegistry::setTableLocator($locator);

        $captured = [];
        $pipeline = $this->getMockBuilder(InvoicePipelineService::class)
            ->disableOriginalConstructor()->onlyMethods(['saveAndAdvance'])->getMock();
        $pipeline->method('saveAndAdvance')->willReturnCallback(
            function ($inv, $data, $roleId, $userId, $baseUrl = null) use (&$captured): ServiceResult {
                $captured = ['roleId' => $roleId, 'userId' => $userId, 'baseUrl' => $baseUrl];

                return ServiceResult::ok(['advanced' => true, 'nextStatus' => 'contabilidad', 'advanceErrors' => []]);
            },
        );

        $history = $this->createMock(InvoiceHistoryService::class);

        $strategy = new InvoiceApprovalStrategy($history, $pipeline);
        $result = $strategy->apply(5, 'approve', null, 7, null);

        $this->assertTrue($result);
        $this->assertSame(99, $captured['roleId']);
        $this->assertSame(7, $captured['userId']);
        $this->assertIsInt($captured['userId']);
    }

    public function testApplyRejectSetsRejectedStatusAndNeverCallsPipeline(): void
    {
        $invoice = new Invoice(['id' => 11, 'pipeline_status' => 'aprobacion']);

        $invoicesTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()->onlyMethods(['get', 'save'])->getMock();
        $invoicesTable->method('get')->willReturn($invoice);
        $invoicesTable->method('save')->willReturn($invoice);

        $locator = new TableLocator();
        $locator->set('Invoices', $invoicesTable);
        TableRegistry::setTableLocator($locator);

        $pipeline = $this->getMockBuilder(InvoicePipelineService::class)
            ->disableOriginalConstructor()->onlyMethods(['saveAndAdvance'])->getMock();
        $pipeline->expects($this->never())->method('saveAndAdvance');

        $history = $this->getMockBuilder(InvoiceHistoryService::class)
            ->disableOriginalConstructor()->onlyMethods(['recordFieldChange'])->getMock();
        $history->expects($this->once())->method('recordFieldChange')->with(
            11,
            'area_approval',
            InvoiceConstants::APPROVAL_PENDING,
            InvoiceConstants::APPROVAL_REJECTED,
            3,
        );

        $strategy = new InvoiceApprovalStrategy($history, $pipeline);
        $result = $strategy->apply(11, 'reject', null, 3, null);

        $this->assertTrue($result);
        $this->assertSame(InvoiceConstants::APPROVAL_REJECTED, $invoice->area_approval);
    }
}

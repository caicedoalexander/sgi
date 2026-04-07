<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\LegalizationConstants;
use App\Constants\NoveltyConstants;
use App\Constants\PettyCashConstants;
use Cake\ORM\TableRegistry;
use Exception;

class SidebarCounterService
{
    private InvoicePipelineService $invoicePipeline;
    private NoveltyPipelineService $noveltyPipeline;

    public function __construct(
        ?InvoicePipelineService $invoicePipeline = null,
        ?NoveltyPipelineService $noveltyPipeline = null,
    ) {
        $this->invoicePipeline = $invoicePipeline ?? new InvoicePipelineService();
        $this->noveltyPipeline = $noveltyPipeline ?? new NoveltyPipelineService();
    }

    /**
     * Get all sidebar counters for a given role.
     *
     * @param string $roleName Current user's role name.
     * @return array<string, mixed> All counter values keyed by name.
     */
    public function getCounters(string $roleName): array
    {
        try {
            return [
                'sidebarCounters' => $this->getInvoiceStatusCounters($roleName),
                'totalInvoicesCount' => $this->getCount('Invoices'),
                'rejectedInvoicesCount' => $this->getCount(
                    'Invoices',
                    ['area_approval' => InvoiceConstants::APPROVAL_REJECTED],
                ),
                'overdueInvoicesCount' => $this->getOverdueInvoicesCount(),
                'pettyCashCount' => $this->getCount(
                    'PettyCashRecords',
                    ['status !=' => PettyCashConstants::STATUS_PAGADO],
                ),
                'legalizationCount' => $this->getCount(
                    'LegalizationRecords',
                    ['status !=' => LegalizationConstants::STATUS_PAGADO],
                ),
                'noveltiesCount' => $this->getNoveltiesCount($roleName),
                'rejectedNoveltiesCount' => $this->getCount(
                    'EmployeeNovelties',
                    ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA],
                ),
                'activeNoveltiesCount' => $this->getActiveNoveltiesCount(),
                'liquidationCounters' => $this->getLiquidationCounters(),
            ];
        } catch (Exception $e) {
            return [
                'sidebarCounters' => [],
                'totalInvoicesCount' => 0,
                'rejectedInvoicesCount' => 0,
                'overdueInvoicesCount' => 0,
                'pettyCashCount' => 0,
                'legalizationCount' => 0,
                'noveltiesCount' => 0,
                'rejectedNoveltiesCount' => 0,
                'activeNoveltiesCount' => 0,
                'liquidationCounters' => [],
            ];
        }
    }

    private function getInvoiceStatusCounters(string $roleName): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $visibleStatuses = $this->invoicePipeline->getVisibleStatuses($roleName);

        $counters = [];
        foreach ($visibleStatuses as $status) {
            $counters[$status] = $invoicesTable->find()
                ->where(['pipeline_status' => $status])
                ->count();
        }

        return $counters;
    }

    private function getOverdueInvoicesCount(): int
    {
        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where([
                'due_date <' => date('Y-m-d'),
                'pipeline_status !=' => InvoiceConstants::STATUS_PAGADA,
            ])
            ->count();
    }

    private function getNoveltiesCount(string $roleName): int
    {
        $noveltyVisibleStatuses = $this->noveltyPipeline->getVisibleStatuses($roleName);
        if (empty($noveltyVisibleStatuses)) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('EmployeeNovelties')->find()
            ->where([
                'pipeline_status IN' => $noveltyVisibleStatuses,
                'pipeline_status !=' => NoveltyConstants::STATUS_RECHAZADA,
            ])
            ->where(function ($exp) {
                return $exp->or([
                    'pipeline_status !=' => NoveltyConstants::STATUS_CONTABILIDAD,
                    'liquidation_doc_id IS' => null,
                ]);
            })
            ->count();
    }

    private function getActiveNoveltiesCount(): int
    {
        $today = date('Y-m-d');

        return TableRegistry::getTableLocator()->get('EmployeeNovelties')->find()
            ->where([
                'pipeline_status IN' => NoveltyConstants::ACTIVE_STATUSES,
            ])
            ->where(function ($exp) use ($today) {
                return $exp->or([
                    $exp->and([
                        'schedule_type' => NoveltyConstants::SCHEDULE_DAYS,
                        'start_date <=' => $today,
                        'end_date >=' => $today,
                    ]),
                    $exp->and([
                        'schedule_type' => NoveltyConstants::SCHEDULE_HOURS,
                        'permission_date' => $today,
                    ]),
                ]);
            })
            ->count();
    }

    private function getLiquidationCounters(): array
    {
        $liquidationTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $counters = [];
        $statuses = [
            NoveltyConstants::STATUS_CONTABILIDAD,
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
        ];
        foreach ($statuses as $status) {
            $counters[$status] = $liquidationTable->find()
                ->where(['pipeline_status' => $status])
                ->count();
        }

        return $counters;
    }

    private function getCount(string $tableName, array $conditions = []): int
    {
        $query = TableRegistry::getTableLocator()->get($tableName)->find();
        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query->count();
    }
}

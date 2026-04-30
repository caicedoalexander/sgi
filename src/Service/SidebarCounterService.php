<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use App\Constants\PettyCashConstants;
use Cake\ORM\TableRegistry;
use Exception;

class SidebarCounterService
{
    private InvoicePipelineService $invoicePipeline;
    private NoveltyPipelineService $noveltyPipeline;
    private PettyCashService $pettyCashService;

    public function __construct(
        ?InvoicePipelineService $invoicePipeline = null,
        ?NoveltyPipelineService $noveltyPipeline = null,
        ?PettyCashService $pettyCashService = null,
    ) {
        $this->invoicePipeline = $invoicePipeline ?? new InvoicePipelineService();
        $this->noveltyPipeline = $noveltyPipeline ?? new NoveltyPipelineService();
        $this->pettyCashService = $pettyCashService ?? new PettyCashService();
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
                'totalInvoicesCount' => $this->getCount(
                    'Invoices',
                    ['document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO],
                ),
                'rejectedInvoicesCount' => $this->getCount(
                    'Invoices',
                    [
                        'area_approval' => InvoiceConstants::APPROVAL_REJECTED,
                        'document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
                    ],
                ),
                'overdueInvoicesCount' => $this->getOverdueInvoicesCount(),
                'pettyCashCount' => $this->getCount(
                    'PettyCashRecords',
                    ['status !=' => PettyCashConstants::STATUS_PAGADO],
                ),
                'pettyCashMineCount' => $this->getPettyCashMineCount($roleName),
                'advancesMineCount' => $this->getAdvancesMineCount($roleName),
                'noveltiesCount' => $this->getNoveltiesCount($roleName),
                'rejectedNoveltiesCount' => $this->getCount(
                    'EmployeeNovelties',
                    ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA],
                ),
                'activeNoveltiesCount' => $this->getActiveNoveltiesCount(),
                'liquidationMineCount' => $this->getLiquidationMineCount($roleName),
                'liquidationRejectedCount' => $this->getCount(
                    'NoveltyLiquidationDocs',
                    ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA],
                ),
                'advancesPendingLegalizationCount' => $this->getCount(
                    'AdvanceLegalizations',
                    ['status !=' => AdvanceConstants::STATUS_LEGALIZADA],
                ),
            ];
        } catch (Exception $e) {
            return [
                'sidebarCounters' => [],
                'totalInvoicesCount' => 0,
                'rejectedInvoicesCount' => 0,
                'overdueInvoicesCount' => 0,
                'pettyCashCount' => 0,
                'pettyCashMineCount' => 0,
                'advancesMineCount' => 0,
                'noveltiesCount' => 0,
                'rejectedNoveltiesCount' => 0,
                'activeNoveltiesCount' => 0,
                'liquidationMineCount' => 0,
                'liquidationRejectedCount' => 0,
                'advancesPendingLegalizationCount' => 0,
            ];
        }
    }

    private function getInvoiceStatusCounters(string $roleName): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $visibleStatuses = $this->invoicePipeline->getVisibleStatuses($roleName);

        $counters = [];
        foreach ($visibleStatuses as $status) {
            // `legalizada` no es un estado del pipeline normal — se reporta vía
            // advancesPendingLegalizationCount, no como contador de pendientes.
            if ($status === InvoiceConstants::STATUS_LEGALIZADA) {
                continue;
            }
            $counters[$status] = $invoicesTable->find()
                ->where([
                    'pipeline_status' => $status,
                    'document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
                ])
                ->count();
        }

        return $counters;
    }

    private function getOverdueInvoicesCount(): int
    {
        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where([
                'document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'due_date <' => date('Y-m-d'),
                'pipeline_status NOT IN' => [
                    InvoiceConstants::STATUS_PAGADA,
                    InvoiceConstants::STATUS_LEGALIZADA,
                ],
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

    /**
     * Count advances visible to the current role.
     */
    private function getAdvancesMineCount(string $roleName): int
    {
        $visibleStatuses = $this->invoicePipeline->getVisibleAdvanceStatuses($roleName);
        if (empty($visibleStatuses)) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where([
                'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'pipeline_status IN' => $visibleStatuses,
            ])
            ->count();
    }

    /**
     * Count petty cash records visible to the current role.
     */
    private function getPettyCashMineCount(string $roleName): int
    {
        $visibleStatuses = $this->pettyCashService->getVisibleStatuses($roleName);
        if (empty($visibleStatuses)) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('PettyCashRecords')->find()
            ->where(['status IN' => $visibleStatuses])
            ->count();
    }

    private function getLiquidationMineCount(string $roleName): int
    {
        $visibleStatuses = $this->noveltyPipeline->getVisibleLiquidationStatuses($roleName);
        if (empty($visibleStatuses)) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs')->find()
            ->where(['pipeline_status IN' => $visibleStatuses])
            ->count();
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

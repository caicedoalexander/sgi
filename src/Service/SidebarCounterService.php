<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use App\Constants\PettyCashConstants;
use App\Constants\RefundConstants;
use Cake\Cache\Cache;
use Cake\Database\Exception\DatabaseException;
use Cake\ORM\TableRegistry;

class SidebarCounterService
{
    private StructuredLogger $logger;

    /**
     * @param \App\Service\InvoicePipelineService $invoicePipeline Invoice pipeline.
     * @param \App\Service\NoveltyPipelineService $noveltyPipeline Novelty pipeline.
     * @param \App\Service\PettyCashPipelineService $pettyCashService Petty cash service.
     * @param \App\Service\RefundPipelineService $refundService Refund service.
     */
    public function __construct(
        private readonly InvoicePipelineService $invoicePipeline,
        private readonly NoveltyPipelineService $noveltyPipeline,
        private readonly PettyCashPipelineService $pettyCashService,
        private readonly RefundPipelineService $refundService,
    ) {
        $this->logger = new StructuredLogger('Sidebar');
    }

    /**
     * Get all sidebar counters for a given role.
     *
     * @param int $roleId Current user's role id.
     * @return array<string, mixed> All counter values keyed by name.
     */
    public function getCounters(int $roleId): array
    {
        return Cache::remember(
            "sidebar_counters_{$roleId}",
            function () use ($roleId) {
                try {
                    return $this->_buildCounters($roleId);
                } catch (DatabaseException $e) {
                    $this->logger->error('sidebar_counters_failed', [
                        'role_id' => $roleId,
                        'exception' => $e->getMessage(),
                    ]);

                    return $this->_emptyCounters();
                }
            },
            'sidebar',
        );
    }

    private function _buildCounters(int $roleId): array
    {
        return [
            'sidebarCounters' => $this->getInvoiceStatusCounters($roleId),
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
                ['status !=' => PettyCashConstants::STATUS_PAGADA],
            ),
            'pettyCashMineCount' => $this->getPettyCashMineCount($roleId),
            'refundsCount' => $this->getCount(
                'Refunds',
                ['status !=' => RefundConstants::STATUS_PAGADA],
            ),
            'refundsMineCount' => $this->getRefundsMineCount($roleId),
            'advancesMineCount' => $this->getAdvancesMineCount($roleId),
            'noveltiesCount' => $this->getNoveltiesCount($roleId),
            'rejectedNoveltiesCount' => $this->getCount(
                'EmployeeNovelties',
                ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA],
            ),
            'activeNoveltiesCount' => $this->getActiveNoveltiesCount(),
            'liquidationMineCount' => $this->getLiquidationMineCount($roleId),
            'liquidationRejectedCount' => $this->getCount(
                'NoveltyLiquidationDocs',
                ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA],
            ),
            'advancesPendingLegalizationCount' => $this->getCount(
                'AdvanceLegalizations',
                ['status !=' => AdvanceConstants::STATUS_LEGALIZADA],
            ),
        ];
    }

    private function _emptyCounters(): array
    {
        return [
            'sidebarCounters' => [],
            'totalInvoicesCount' => 0,
            'rejectedInvoicesCount' => 0,
            'overdueInvoicesCount' => 0,
            'pettyCashCount' => 0,
            'pettyCashMineCount' => 0,
            'refundsCount' => 0,
            'refundsMineCount' => 0,
            'advancesMineCount' => 0,
            'noveltiesCount' => 0,
            'rejectedNoveltiesCount' => 0,
            'activeNoveltiesCount' => 0,
            'liquidationMineCount' => 0,
            'liquidationRejectedCount' => 0,
            'advancesPendingLegalizationCount' => 0,
        ];
    }

    private function getInvoiceStatusCounters(int $roleId): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $visibleStatuses = $this->invoicePipeline->getVisibleStatuses($roleId);

        $counters = [];
        foreach ($visibleStatuses as $status) {
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

    private function getNoveltiesCount(int $roleId): int
    {
        $noveltyVisibleStatuses = $this->noveltyPipeline->getVisibleStatuses($roleId);
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
    private function getAdvancesMineCount(int $roleId): int
    {
        $visibleStatuses = $this->invoicePipeline->getVisibleStatuses($roleId);
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
    private function getPettyCashMineCount(int $roleId): int
    {
        $visibleStatuses = $this->pettyCashService->getVisibleStatuses($roleId);
        if (empty($visibleStatuses)) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('PettyCashRecords')->find()
            ->where(['status IN' => $visibleStatuses])
            ->count();
    }

    /**
     * Count refund records visible to the current role.
     */
    private function getRefundsMineCount(int $roleId): int
    {
        $visibleStatuses = $this->refundService->getVisibleStatuses($roleId);
        if (empty($visibleStatuses)) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('Refunds')->find()
            ->where(['status IN' => $visibleStatuses])
            ->count();
    }

    private function getLiquidationMineCount(int $roleId): int
    {
        $visibleStatuses = $this->noveltyPipeline->getVisibleLiquidationStatuses($roleId);
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

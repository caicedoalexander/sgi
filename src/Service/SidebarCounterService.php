<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\AssetAlertConstants;
use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use App\Constants\PettyCashConstants;
use App\Constants\RefundConstants;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
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
     * @param \App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy $legalizationPolicy Pasos operables del pipeline `legalizations`.
     * @param \App\Service\PaymentSchedulingPipelineService $paymentSchedulingService Payment scheduling pipeline.
     */
    public function __construct(
        private readonly InvoicePipelineService $invoicePipeline,
        private readonly NoveltyPipelineService $noveltyPipeline,
        private readonly PettyCashPipelineService $pettyCashService,
        private readonly RefundPipelineService $refundService,
        private readonly AdvanceLegalizationActionPolicy $legalizationPolicy,
        private readonly PaymentSchedulingPipelineService $paymentSchedulingService,
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

    /**
     * Compute every sidebar counter for a role in one pass.
     *
     * @param int $roleId Current user's role id.
     * @return array<string, mixed> Counter values keyed by name.
     */
    private function _buildCounters(int $roleId): array
    {
        $sidebarCounters = $this->getInvoiceStatusCounters($roleId);
        $advancesMine = $this->getAdvancesMineCount($roleId);
        $advancesPendingLegalization = $this->getAdvancesPendingLegalizationCount($roleId);
        $pettyCashMine = $this->getPettyCashMineCount($roleId);
        $refundsMine = $this->getRefundsMineCount($roleId);
        $noveltiesMine = $this->getNoveltiesCount($roleId);
        $liquidationMine = $this->getLiquidationMineCount($roleId);
        $paymentSchedulingsMine = $this->getPaymentSchedulingsMineCount($roleId);

        $myPendingTotal = (int)array_sum($sidebarCounters)
            + $advancesMine + $advancesPendingLegalization + $pettyCashMine
            + $refundsMine + $noveltiesMine + $liquidationMine + $paymentSchedulingsMine;

        return [
            'sidebarCounters' => $sidebarCounters,
            'totalInvoicesCount' => $this->getCount(
                'Invoices',
                ['document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO],
            ),
            'rejectedInvoicesCount' => $this->getRejectedInvoicesCount($roleId),
            'overdueInvoicesCount' => $this->getOverdueInvoicesCount($roleId),
            'pettyCashCount' => $this->getCount(
                'PettyCashRecords',
                ['status !=' => PettyCashConstants::STATUS_PAGADA],
            ),
            'pettyCashMineCount' => $pettyCashMine,
            'refundsCount' => $this->getCount(
                'Refunds',
                ['status !=' => RefundConstants::STATUS_PAGADA],
            ),
            'refundsMineCount' => $refundsMine,
            'advancesMineCount' => $advancesMine,
            'noveltiesCount' => $noveltiesMine,
            'rejectedNoveltiesCount' => $this->getCount(
                'EmployeeNovelties',
                ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA],
            ),
            'activeNoveltiesCount' => $this->getActiveNoveltiesCount(),
            'liquidationMineCount' => $liquidationMine,
            'liquidationRejectedCount' => $this->getCount(
                'NoveltyLiquidationDocs',
                ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA],
            ),
            'advancesPendingLegalizationCount' => $advancesPendingLegalization,
            'paymentSchedulingsMineCount' => $paymentSchedulingsMine,
            'myPendingTotal' => $myPendingTotal,
            'openAlertsCount' => TableRegistry::getTableLocator()->get('AssetAlerts')
                ->find()->where(['status' => AssetAlertConstants::STATUS_ABIERTA])->count(),
        ];
    }

    /**
     * Zeroed counter set returned when counter computation fails.
     *
     * @return array<string, mixed>
     */
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
            'paymentSchedulingsMineCount' => 0,
            'myPendingTotal' => 0,
            'openAlertsCount' => 0,
        ];
    }

    /**
     * Count parentless, non-advance invoices per pipeline status the role can see.
     *
     * @param int $roleId Current user's role id.
     * @return array<string, int> Count keyed by visible pipeline status.
     */
    private function getInvoiceStatusCounters(int $roleId): array
    {
        $visibleStatuses = $this->invoicePipeline->getVisibleStatuses($roleId);
        $statuses = array_values(array_filter(
            $visibleStatuses,
            static fn($status): bool => $status !== InvoiceConstants::STATUS_LEGALIZADA,
        ));
        if ($statuses === []) {
            return [];
        }

        // Un solo GROUP BY pipeline_status en vez de un COUNT por estado (B1).
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $rows = $invoicesTable->find('withoutParent')
            ->select([
                'pipeline_status' => 'Invoices.pipeline_status',
                'cnt' => $invoicesTable->find()->func()->count('*'),
            ])
            ->where([
                'pipeline_status IN' => $statuses,
                'document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
            ])
            ->groupBy('Invoices.pipeline_status')
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->pipeline_status] = (int)$row->cnt;
        }

        // Preservar la forma original: una clave por estado visible, con 0 si no
        // hay filas (antes cada COUNT vacío devolvía 0 explícitamente).
        $counters = [];
        foreach ($statuses as $status) {
            $counters[$status] = $counts[$status] ?? 0;
        }

        return $counters;
    }

    /**
     * Espejo exacto de InvoicesController::rejected(): pasos operables del rol,
     * sin facturas con padre. Si diverge, el badge miente sobre su lista.
     */
    private function getRejectedInvoicesCount(int $roleId): int
    {
        $statuses = $this->invoicePipeline->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find('withoutParent')
            ->where([
                'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.area_approval' => InvoiceConstants::APPROVAL_REJECTED,
                'Invoices.pipeline_status IN' => $statuses,
            ])
            ->count();
    }

    /**
     * Espejo exacto de InvoicesController::overdue(): pasos operables del rol,
     * sin facturas con padre, solo tipos de documento con vencimiento real.
     */
    private function getOverdueInvoicesCount(int $roleId): int
    {
        $statuses = $this->invoicePipeline->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find('withoutParent')
            ->where([
                'Invoices.document_type IN' => InvoiceConstants::DOCTYPES_WITH_DUE_DATE,
                'Invoices.due_date <' => date('Y-m-d'),
                'Invoices.pipeline_status IN' => $statuses,
                'Invoices.pipeline_status NOT IN' => [
                    InvoiceConstants::STATUS_PAGADA,
                    InvoiceConstants::STATUS_LEGALIZADA,
                ],
            ])
            ->count();
    }

    /**
     * Count novelties in statuses the role can operate, excluding rejected ones
     * and those already carried by a liquidation doc in accounting.
     *
     * @param int $roleId Current user's role id.
     * @return int
     */
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

    /**
     * Count novelties active today (by day range or by permission hour).
     *
     * @return int
     */
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

    /**
     * Count liquidation docs in statuses the role can operate.
     *
     * @param int $roleId Current user's role id.
     * @return int
     */
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

    /**
     * Cuenta los anticipos pendientes de legalización cuyo paso actual el rol
     * puede operar. Espejo de `AdvancesController::pendingLegalization()`.
     *
     * Ojo: `getAdvancesMineCount()` usa `invoicePipeline` porque el Anticipo vive
     * en el pipeline de **facturas**. La legalización es otro pipeline y necesita
     * otra fuente — de ahí `legalizationPolicy`.
     */
    private function getAdvancesPendingLegalizationCount(int $roleId): int
    {
        $visibleSteps = $this->legalizationPolicy->getVisibleStatuses($roleId);
        if ($visibleSteps === []) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            ])
            ->innerJoinWith('AdvanceLegalization', function ($q) use ($visibleSteps) {
                return $q->where([
                    'AdvanceLegalization.status IN' => $visibleSteps,
                    'AdvanceLegalization.status !=' => AdvanceConstants::STATUS_LEGALIZADA,
                ]);
            })
            ->count();
    }

    /**
     * Cuenta las programaciones de pago cuyo estado el rol puede operar.
     */
    private function getPaymentSchedulingsMineCount(int $roleId): int
    {
        $visibleStatuses = $this->paymentSchedulingService->getVisibleStatuses($roleId);
        if ($visibleStatuses === []) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('PaymentSchedulings')->find()
            ->where(['pipeline_status IN' => $visibleStatuses])
            ->count();
    }

    /**
     * Count rows of a table, optionally constrained by conditions.
     *
     * @param string $tableName CakePHP table alias to count.
     * @param array $conditions Optional where conditions.
     * @return int
     */
    private function getCount(string $tableName, array $conditions = []): int
    {
        $query = TableRegistry::getTableLocator()->get($tableName)->find();
        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query->count();
    }
}

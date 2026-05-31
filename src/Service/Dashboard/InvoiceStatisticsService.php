<?php
declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Constants\InvoiceConstants;
use App\Service\StructuredLogger;
use Cake\Database\Exception\DatabaseException;
use Cake\ORM\TableRegistry;

/**
 * Invoice-related dashboard statistics.
 */
class InvoiceStatisticsService
{
    private StructuredLogger $logger;

    public function __construct()
    {
        $this->logger = new StructuredLogger('Dashboard.InvoiceStats');
    }

    /**
     * Get invoice pipeline status counts.
     *
     * @return array
     */
    public function getStats(): array
    {
        // Un solo GROUP BY pipeline_status en lugar de un COUNT por estado (M1).
        // `rechazada` se cuenta aparte porque depende de `area_approval`, no del
        // estado de pipeline (puede solaparse con cualquier estado).
        $countsByStatus = $this->_countByStatus();

        return [
            'total' => array_sum($countsByStatus),
            'aprobacion' => $countsByStatus[InvoiceConstants::STATUS_APROBACION] ?? 0,
            'contabilidad' => $countsByStatus[InvoiceConstants::STATUS_CONTABILIDAD] ?? 0,
            'tesoreria' => $countsByStatus[InvoiceConstants::STATUS_TESORERIA] ?? 0,
            'pagada' => $countsByStatus[InvoiceConstants::STATUS_PAGADA] ?? 0,
            'rechazada' => $this->_safeCount('Invoices', ['area_approval' => InvoiceConstants::APPROVAL_REJECTED]),
        ];
    }

    /**
     * Get recent invoices for dashboard widget.
     *
     * @param int $limit Number of invoices to return.
     * @return array
     */
    public function getRecent(int $limit = 5): array
    {
        try {
            return TableRegistry::getTableLocator()->get('Invoices')
                ->find()
                ->select(['id', 'invoice_number', 'pipeline_status', 'area_approval', 'modified'])
                ->contain(['Providers' => ['fields' => ['id', 'name']]])
                ->orderByDesc('Invoices.modified')
                ->limit($limit)
                ->toArray();
        } catch (DatabaseException $e) {
            // UI degradable: dashboard no debe romper si la query de "recientes" falla.
            $this->logger->error('recent_invoices_query_failed', [
                'method' => __METHOD__,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get invoice financial stats for a date range.
     *
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    public function getFinancialStats(string $from, string $to): array
    {
        try {
            $table = TableRegistry::getTableLocator()->get('Invoices');
            $to .= ' 23:59:59';

            // total_paid + total_in_process + avg_amount comparten el rango de
            // fecha y agregan sobre `amount` → una sola query con SUM(CASE …) (B7).
            $agg = $table->getConnection()->execute(
                "SELECT
                    COALESCE(SUM(CASE WHEN pipeline_status = ? THEN amount ELSE 0 END), 0) AS total_paid,
                    COALESCE(SUM(CASE WHEN pipeline_status IN (?, ?, ?)
                                       AND (area_approval IS NULL OR area_approval <> ?)
                                      THEN amount ELSE 0 END), 0) AS total_in_process,
                    COALESCE(AVG(amount), 0) AS avg_amount
                 FROM invoices
                 WHERE created >= ? AND created <= ?",
                [
                    InvoiceConstants::STATUS_PAGADA,
                    InvoiceConstants::STATUS_APROBACION,
                    InvoiceConstants::STATUS_CONTABILIDAD,
                    InvoiceConstants::STATUS_TESORERIA,
                    InvoiceConstants::APPROVAL_REJECTED,
                    $from,
                    $to,
                ],
            )->fetch('assoc');

            // `overdue` tiene condiciones distintas (sin rango de fecha) → query aparte.
            $overdue = $table->find()
                ->where([
                    'due_date <' => date('Y-m-d'),
                    'pipeline_status !=' => InvoiceConstants::STATUS_PAGADA,
                    'OR' => [
                        'area_approval IS' => null,
                        'area_approval !=' => InvoiceConstants::APPROVAL_REJECTED,
                    ],
                ])
                ->count();

            return [
                'total_paid' => (float)($agg['total_paid'] ?? 0),
                'total_in_process' => (float)($agg['total_in_process'] ?? 0),
                'avg_amount' => (float)($agg['avg_amount'] ?? 0),
                'overdue' => $overdue,
            ];
        } catch (DatabaseException $e) {
            // UI degradable: dashboard muestra stats vacíos en lugar de fallar.
            $this->logger->error('financial_stats_query_failed', [
                'method' => __METHOD__,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get invoice chart data for a date range.
     *
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    public function getChartData(string $from, string $to): array
    {
        try {
            $table = TableRegistry::getTableLocator()->get('Invoices');
            $dateConditions = [
                'Invoices.created >=' => $from,
                'Invoices.created <=' => $to . ' 23:59:59',
            ];

            // Un solo GROUP BY pipeline_status en lugar de un SUM por estado (M1).
            $sumsByStatus = $this->_sumAmountByStatus($dateConditions);
            $statusAmounts = [];
            foreach (InvoiceConstants::PIPELINE_STATUSES as $status) {
                $statusAmounts[$status] = (float)($sumsByStatus[$status] ?? 0);
            }

            $rejected = $table->find()
                ->where(array_merge(
                    ['area_approval' => InvoiceConstants::APPROVAL_REJECTED],
                    $dateConditions,
                ))
                ->select(['total' => $table->find()->func()->sum('amount')])
                ->first();
            $statusAmounts['rechazada'] = (float)($rejected->total ?? 0);

            $monthlyData = $table->getConnection()->execute(
                "SELECT DATE_FORMAT(created, '%Y-%m') as month,
                        COUNT(*) as count,
                        COALESCE(SUM(amount), 0) as total
                 FROM invoices
                 WHERE created >= ? AND created <= ?
                 GROUP BY DATE_FORMAT(created, '%Y-%m')
                 ORDER BY month ASC",
                [$from, $to . ' 23:59:59'],
            )->fetchAll('assoc');

            return [
                'donut_status' => $statusAmounts,
                'monthly' => $monthlyData,
            ];
        } catch (DatabaseException $e) {
            // UI degradable: dashboard sin charts en lugar de fallar.
            $this->logger->error('chart_data_query_failed', [
                'method' => __METHOD__,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Cuenta facturas agrupadas por `pipeline_status` en una sola query (M1).
     *
     * @param array $dateConditions Condiciones de rango de fecha opcionales.
     * @return array<string, int> Mapa estado => cantidad.
     */
    private function _countByStatus(array $dateConditions = []): array
    {
        try {
            $table = TableRegistry::getTableLocator()->get('Invoices');
            $query = $table->find()
                ->select([
                    'pipeline_status' => 'Invoices.pipeline_status',
                    'cnt' => $table->find()->func()->count('*'),
                ])
                ->groupBy('Invoices.pipeline_status');
            if ($dateConditions !== []) {
                $query->where($dateConditions);
            }

            $map = [];
            foreach ($query->all() as $row) {
                $map[$row->pipeline_status] = (int)$row->cnt;
            }

            return $map;
        } catch (DatabaseException $e) {
            $this->logger->error('count_by_status_query_failed', [
                'method' => __METHOD__,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Suma `amount` agrupado por `pipeline_status` en una sola query (M1).
     *
     * @param array $dateConditions Condiciones de rango de fecha.
     * @return array<string, float> Mapa estado => suma de amount.
     */
    private function _sumAmountByStatus(array $dateConditions): array
    {
        $table = TableRegistry::getTableLocator()->get('Invoices');
        $query = $table->find()
            ->select([
                'pipeline_status' => 'Invoices.pipeline_status',
                'total' => $table->find()->func()->sum('amount'),
            ])
            ->where($dateConditions)
            ->groupBy('Invoices.pipeline_status');

        $map = [];
        foreach ($query->all() as $row) {
            $map[$row->pipeline_status] = (float)($row->total ?? 0);
        }

        return $map;
    }

    /**
     * @param string $tableName Table name.
     * @param array $conditions Query conditions.
     * @return int
     */
    private function _safeCount(string $tableName, array $conditions = []): int
    {
        try {
            $query = TableRegistry::getTableLocator()->get($tableName)->find();
            if (!empty($conditions)) {
                $query->where($conditions);
            }

            return $query->count();
        } catch (DatabaseException $e) {
            // UI degradable: contador queda en 0 si la query falla.
            $this->logger->error('count_query_failed', [
                'method' => __METHOD__,
                'table' => $tableName,
                'exception' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}

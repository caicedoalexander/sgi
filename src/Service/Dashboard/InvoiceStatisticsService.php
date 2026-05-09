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
        return [
            'total' => $this->_safeCount('Invoices'),
            'aprobacion' => $this->_safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_APROBACION]),
            'contabilidad' => $this->_safeCount(
                'Invoices',
                ['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD],
            ),
            'tesoreria' => $this->_safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_TESORERIA]),
            'autorizacion_pago' => $this->_safeCount(
                'Invoices',
                ['pipeline_status' => InvoiceConstants::STATUS_AUTORIZACION_PAGO],
            ),
            'verificacion_pago' => $this->_safeCount(
                'Invoices',
                ['pipeline_status' => InvoiceConstants::STATUS_VERIFICACION_PAGO],
            ),
            'pagada' => $this->_safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_PAGADA]),
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
            $dateConditions = [
                'Invoices.created >=' => $from,
                'Invoices.created <=' => $to . ' 23:59:59',
            ];

            $totalPaid = $table->find()
                ->where(array_merge(
                    ['pipeline_status' => InvoiceConstants::STATUS_PAGADA],
                    $dateConditions,
                ))
                ->select(['total' => $table->find()->func()->sum('amount')])
                ->first();

            $totalInProcess = $table->find()
                ->where(array_merge([
                    'pipeline_status IN' => [
                        InvoiceConstants::STATUS_APROBACION,
                        InvoiceConstants::STATUS_CONTABILIDAD,
                        InvoiceConstants::STATUS_TESORERIA,
                        InvoiceConstants::STATUS_AUTORIZACION_PAGO,
                        InvoiceConstants::STATUS_VERIFICACION_PAGO,
                    ],
                    'OR' => [
                        'area_approval IS' => null,
                        'area_approval !=' => InvoiceConstants::APPROVAL_REJECTED,
                    ],
                ], $dateConditions))
                ->select(['total' => $table->find()->func()->sum('amount')])
                ->first();

            $avgAmount = $table->find()
                ->where($dateConditions)
                ->select(['avg' => $table->find()->func()->avg('amount')])
                ->first();

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
                'total_paid' => (float)($totalPaid->total ?? 0),
                'total_in_process' => (float)($totalInProcess->total ?? 0),
                'avg_amount' => (float)($avgAmount->avg ?? 0),
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

            $statusAmounts = [];
            foreach (InvoiceConstants::PIPELINE_STATUSES as $status) {
                $result = $table->find()
                    ->where(array_merge(['pipeline_status' => $status], $dateConditions))
                    ->select(['total' => $table->find()->func()->sum('amount')])
                    ->first();
                $statusAmounts[$status] = (float)($result->total ?? 0);
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

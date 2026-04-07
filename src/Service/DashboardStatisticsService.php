<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\ContractTypeConstants;
use App\Constants\EmployeeStatusConstants;
use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use Cake\ORM\TableRegistry;
use Exception;

class DashboardStatisticsService
{
    /**
     * Get invoice pipeline status counts.
     */
    public function getInvoiceStats(): array
    {
        return [
            'total' => $this->safeCount('Invoices'),
            'aprobacion' => $this->safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_APROBACION]),
            'contabilidad' => $this->safeCount(
                'Invoices',
                ['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD],
            ),
            'tesoreria' => $this->safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_TESORERIA]),
            'pagada' => $this->safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_PAGADA]),
            'rechazada' => $this->safeCount('Invoices', ['area_approval' => InvoiceConstants::APPROVAL_REJECTED]),
        ];
    }

    /**
     * Get recent invoices for dashboard widget.
     */
    public function getRecentInvoices(int $limit = 5): array
    {
        try {
            return TableRegistry::getTableLocator()->get('Invoices')
                ->find()
                ->select(['id', 'invoice_number', 'pipeline_status', 'area_approval', 'modified'])
                ->contain(['Providers' => ['fields' => ['id', 'name']]])
                ->orderByDesc('modified')
                ->limit($limit)
                ->toArray();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get invoice financial stats for a date range.
     */
    public function getInvoiceFinancialStats(string $from, string $to): array
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
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get invoice chart data for a date range.
     */
    public function getInvoiceChartData(string $from, string $to): array
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
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get recent novelties for dashboard widget.
     */
    public function getRecentNovelties(int $limit = 5): array
    {
        try {
            return TableRegistry::getTableLocator()->get('EmployeeNovelties')
                ->find()
                ->select(['id', 'employee_id', 'novelty_type_id', 'created'])
                ->contain([
                    'Employees' => ['fields' => ['id', 'first_name', 'last_name1', 'last_name2']],
                    'NoveltyTypes' => ['fields' => ['id', 'name']],
                ])
                ->orderByDesc('created')
                ->limit($limit)
                ->toArray();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get RRHH basic stats.
     */
    public function getRrhhBasicStats(): array
    {
        $stats = [];
        $stats['active_employees'] = $this->safeCount(
            'Employees',
            ['employee_status_id' => EmployeeStatusConstants::ACTIVO],
        );

        $monthStart = date('Y-m-01 00:00:00');
        $stats['novelties_month'] = $this->safeCount('EmployeeNovelties', ['created >=' => $monthStart]);

        $today = date('Y-m-d');
        try {
            $stats['active_novelties'] = TableRegistry::getTableLocator()->get('EmployeeNovelties')
                ->find()
                ->where(['pipeline_status IN' => NoveltyConstants::ACTIVE_STATUSES])
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
        } catch (Exception $e) {
            $stats['active_novelties'] = 0;
        }

        return $stats;
    }

    /**
     * Get RRHH extended statistics for a date range.
     */
    public function getRrhhExtendedStats(string $from, string $to): array
    {
        try {
            $empTable = TableRegistry::getTableLocator()->get('Employees');

            $avgAge = $empTable->getConnection()->execute(
                "SELECT AVG(TIMESTAMPDIFF(YEAR, birth_date, CURDATE())) as avg_age
                 FROM employees
                 WHERE employee_status_id = ? AND birth_date IS NOT NULL",
                [EmployeeStatusConstants::ACTIVO],
            )->fetch('assoc');

            $avgTenure = $empTable->getConnection()->execute(
                "SELECT AVG(TIMESTAMPDIFF(YEAR, hire_date, CURDATE())) as avg_tenure
                 FROM employees
                 WHERE employee_status_id = ? AND hire_date IS NOT NULL",
                [EmployeeStatusConstants::ACTIVO],
            )->fetch('assoc');

            $newHires = $empTable->find()
                ->where([
                    'employee_status_id' => EmployeeStatusConstants::ACTIVO,
                    'hire_date >=' => $from,
                    'hire_date <=' => $to,
                ])
                ->count();

            $terminations = $empTable->find()
                ->where([
                    'termination_date >=' => $from,
                    'termination_date <=' => $to,
                ])
                ->count();

            return [
                'avg_age' => round((float)($avgAge['avg_age'] ?? 0), 1),
                'avg_tenure' => round((float)($avgTenure['avg_tenure'] ?? 0), 1),
                'new_hires' => $newHires,
                'terminations' => $terminations,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get RRHH chart data for a date range.
     */
    public function getRrhhChartData(string $from, string $to): array
    {
        try {
            $empTable = TableRegistry::getTableLocator()->get('Employees');

            $contractTypes = [];
            foreach (ContractTypeConstants::ALL as $type) {
                $contractTypes[$type] = $empTable->find()
                    ->where([
                        'employee_status_id' => EmployeeStatusConstants::ACTIVO,
                        'contract_type' => $type,
                    ])
                    ->count();
            }

            $monthlyNovelties = TableRegistry::getTableLocator()->get('EmployeeNovelties')
                ->getConnection()->execute(
                    "SELECT DATE_FORMAT(created, '%Y-%m') as month,
                            COUNT(*) as count
                     FROM employee_novelties
                     WHERE created >= ? AND created <= ?
                     GROUP BY DATE_FORMAT(created, '%Y-%m')
                     ORDER BY month ASC",
                    [$from, $to . ' 23:59:59'],
                )->fetchAll('assoc');

            return [
                'donut_contract' => $contractTypes,
                'monthly_novelties' => $monthlyNovelties,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get catalog counts.
     */
    public function getCatalogStats(): array
    {
        return [
            'providers' => $this->safeCount('Providers', ['active' => true]),
            'operation_centers' => $this->safeCount('OperationCenters'),
            'expense_types' => $this->safeCount('ExpenseTypes'),
            'cost_centers' => $this->safeCount('CostCenters'),
        ];
    }

    /**
     * Get admin stats.
     */
    public function getAdminStats(): array
    {
        return [
            'users' => $this->safeCount('Users', ['active' => true]),
            'roles' => $this->safeCount('Roles'),
        ];
    }

    private function safeCount(string $tableName, array $conditions = []): int
    {
        try {
            $query = TableRegistry::getTableLocator()->get($tableName)->find();
            if (!empty($conditions)) {
                $query->where($conditions);
            }

            return $query->count();
        } catch (Exception $e) {
            return 0;
        }
    }
}

<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\ContractTypeConstants;
use App\Constants\EmployeeStatusConstants;
use App\Constants\InvoiceConstants;
use DateTime;
use Exception;

class DashboardController extends AppController
{
    /**
     * Dashboard index action.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function index()
    {
        $identity = $this->Authentication->getIdentity();
        if (!$identity) {
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        [$currentPeriod, $dateFrom, $dateTo] = $this->_getPeriodDates();

        $userPermissions = $this->viewBuilder()->getVar('userPermissions') ?? [];
        $canView = fn(string $module): bool => !empty($userPermissions[$module]['can_view']);

        // --- Facturación ---
        $invoiceStats = [];
        $recentInvoices = [];
        $invoiceFinancialStats = [];
        $invoiceChartData = [];
        if ($canView('invoices')) {
            $invoiceStats = [
                'total' => $this->_safeCount('Invoices'),
                'aprobacion' => $this->_safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_APROBACION]),
                'contabilidad' => $this->_safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD]),
                'tesoreria' => $this->_safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_TESORERIA]),
                'pagada' => $this->_safeCount('Invoices', ['pipeline_status' => InvoiceConstants::STATUS_PAGADA]),
                'rechazada' => $this->_safeCount('Invoices', ['area_approval' => InvoiceConstants::APPROVAL_REJECTED]),
            ];
            $recentInvoices = $this->_safeQuery(function () {
                return $this->fetchTable('Invoices')
                    ->find()
                    ->select(['id', 'invoice_number', 'pipeline_status', 'area_approval', 'modified'])
                    ->contain(['Providers' => ['fields' => ['id', 'name']]])
                    ->orderByDesc('modified')
                    ->limit(5)
                    ->toArray();
            });
            $invoiceFinancialStats = $this->_getInvoiceFinancialStats($dateFrom, $dateTo);
            $invoiceChartData = $this->_getInvoiceChartData($dateFrom, $dateTo);
        }

        // --- RRHH ---
        $rrhhStats = [];
        $recentNovelties = [];
        $rrhhExtendedStats = [];
        $rrhhChartData = [];
        if ($canView('employees') || $canView('employee_novelties')) {
            if ($canView('employees')) {
                $rrhhStats['active_employees'] = $this->_safeCount(
                    'Employees',
                    ['employee_status_id' => EmployeeStatusConstants::ACTIVO],
                );
            }
            if ($canView('employee_novelties')) {
                $monthStart = date('Y-m-01 00:00:00');
                $rrhhStats['novelties_month'] = $this->_safeCount('EmployeeNovelties', ['created >=' => $monthStart]);
                $recentNovelties = $this->_safeQuery(function () {
                    return $this->fetchTable('EmployeeNovelties')
                        ->find()
                        ->select(['id', 'employee_id', 'novelty_type_id', 'created'])
                        ->contain([
                            'Employees' => ['fields' => ['id', 'first_name', 'last_name1', 'last_name2']],
                            'NoveltyTypes' => ['fields' => ['id', 'name']],
                        ])
                        ->orderByDesc('created')
                        ->limit(5)
                        ->toArray();
                });
            }
            $rrhhExtendedStats = $this->_getRrhhExtendedStats($dateFrom, $dateTo);
            $rrhhChartData = $this->_getRrhhChartData($dateFrom, $dateTo);
        }

        // --- Catálogos ---
        $catalogStats = [];
        if ($canView('providers')) {
            $catalogStats['providers'] = $this->_safeCount('Providers', ['active' => true]);
        }
        if ($canView('operation_centers')) {
            $catalogStats['operation_centers'] = $this->_safeCount('OperationCenters');
        }
        if ($canView('expense_types')) {
            $catalogStats['expense_types'] = $this->_safeCount('ExpenseTypes');
        }
        if ($canView('cost_centers')) {
            $catalogStats['cost_centers'] = $this->_safeCount('CostCenters');
        }

        // --- Administración ---
        $adminStats = [];
        if ($canView('users')) {
            $adminStats['users'] = $this->_safeCount('Users', ['active' => true]);
        }
        if ($canView('roles')) {
            $adminStats['roles'] = $this->_safeCount('Roles');
        }

        $this->set(compact(
            'invoiceStats',
            'recentInvoices',
            'invoiceFinancialStats',
            'invoiceChartData',
            'rrhhStats',
            'recentNovelties',
            'rrhhExtendedStats',
            'rrhhChartData',
            'catalogStats',
            'adminStats',
            'currentPeriod',
            'dateFrom',
            'dateTo',
        ));
    }

    /**
     * Safe count query with exception handling.
     *
     * @param string $tableName Table name.
     * @param array $conditions Where conditions.
     * @return int
     */
    private function _safeCount(string $tableName, array $conditions = []): int
    {
        try {
            $query = $this->fetchTable($tableName)->find();
            if (!empty($conditions)) {
                $query->where($conditions);
            }

            return $query->count();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Safe query execution with exception handling.
     *
     * @param callable $fn Query callback.
     * @return array
     */
    private function _safeQuery(callable $fn): array
    {
        try {
            return $fn();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get RRHH extended statistics for dashboard.
     *
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    private function _getRrhhExtendedStats(string $from, string $to): array
    {
        return $this->_safeQuery(function () use ($from, $to) {
            $empTable = $this->fetchTable('Employees');

            // Average age
            $avgAge = $empTable->getConnection()->execute(
                "SELECT AVG(TIMESTAMPDIFF(YEAR, birth_date, CURDATE())) as avg_age
                 FROM employees
                 WHERE employee_status_id = ? AND birth_date IS NOT NULL",
                [EmployeeStatusConstants::ACTIVO],
            )->fetch('assoc');

            // Average tenure (years)
            $avgTenure = $empTable->getConnection()->execute(
                "SELECT AVG(TIMESTAMPDIFF(YEAR, hire_date, CURDATE())) as avg_tenure
                 FROM employees
                 WHERE employee_status_id = ? AND hire_date IS NOT NULL",
                [EmployeeStatusConstants::ACTIVO],
            )->fetch('assoc');

            // New hires in period
            $activeCondition = ['employee_status_id' => EmployeeStatusConstants::ACTIVO];
            $newHires = $empTable->find()
                ->where(array_merge($activeCondition, [
                    'hire_date >=' => $from,
                    'hire_date <=' => $to,
                ]))
                ->count();

            // Terminations in period
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
        });
    }

    /**
     * Get RRHH chart data for dashboard.
     *
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    private function _getRrhhChartData(string $from, string $to): array
    {
        return $this->_safeQuery(function () use ($from, $to) {
            $empTable = $this->fetchTable('Employees');

            // Donut: employees by contract type
            $contractTypes = [];
            foreach (ContractTypeConstants::ALL as $type) {
                $count = $empTable->find()
                    ->where([
                        'employee_status_id' => EmployeeStatusConstants::ACTIVO,
                        'contract_type' => $type,
                    ])
                    ->count();
                $contractTypes[$type] = $count;
            }

            // Bar: novelties per month
            $novTable = $this->fetchTable('EmployeeNovelties');
            $monthlyNovelties = $novTable->getConnection()->execute(
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
        });
    }

    /**
     * Get invoice financial statistics for dashboard.
     *
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    private function _getInvoiceFinancialStats(string $from, string $to): array
    {
        return $this->_safeQuery(function () use ($from, $to) {
            $table = $this->fetchTable('Invoices');
            $dateConditions = ['Invoices.created >=' => $from, 'Invoices.created <=' => $to . ' 23:59:59'];

            $totalPaid = $table->find()
                ->where(array_merge(['pipeline_status' => InvoiceConstants::STATUS_PAGADA], $dateConditions))
                ->select(['total' => $table->find()->func()->sum('amount')])
                ->first();

            $totalInProcess = $table->find()
                ->where(array_merge([
                    'pipeline_status IN' => [InvoiceConstants::STATUS_APROBACION, InvoiceConstants::STATUS_CONTABILIDAD, InvoiceConstants::STATUS_TESORERIA],
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
        });
    }

    /**
     * Get invoice chart data for dashboard.
     *
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    private function _getInvoiceChartData(string $from, string $to): array
    {
        return $this->_safeQuery(function () use ($from, $to) {
            $table = $this->fetchTable('Invoices');
            $dateConditions = ['Invoices.created >=' => $from, 'Invoices.created <=' => $to . ' 23:59:59'];

            // Donut: amount by pipeline status
            $statusAmounts = [];
            foreach (InvoiceConstants::PIPELINE_STATUSES as $status) {
                $result = $table->find()
                    ->where(array_merge(['pipeline_status' => $status], $dateConditions))
                    ->select(['total' => $table->find()->func()->sum('amount')])
                    ->first();
                $statusAmounts[$status] = (float)($result->total ?? 0);
            }
            // Rejected
            $rejected = $table->find()
                ->where(array_merge(['area_approval' => InvoiceConstants::APPROVAL_REJECTED], $dateConditions))
                ->select(['total' => $table->find()->func()->sum('amount')])
                ->first();
            $statusAmounts['rechazada'] = (float)($rejected->total ?? 0);

            // Bar: invoices per month (count + amount)
            $connection = $table->getConnection();
            $monthlyData = $connection->execute(
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
        });
    }

    /**
     * Get period date range from query params.
     *
     * @return array [$period, $from, $to]
     */
    private function _getPeriodDates(): array
    {
        $period = $this->request->getQuery('period', 'month');
        $now = new DateTime();

        switch ($period) {
            case 'quarter':
                $quarterMonth = (int)(ceil((int)$now->format('n') / 3) - 1) * 3 + 1;
                $monthStr = str_pad((string)$quarterMonth, 2, '0', STR_PAD_LEFT);
                $from = (new DateTime($now->format('Y') . '-' . $monthStr . '-01'))
                    ->format('Y-m-d');
                $to = $now->format('Y-m-d');
                break;
            case 'year':
                $from = $now->format('Y') . '-01-01';
                $to = $now->format('Y-m-d');
                break;
            case 'all':
                $from = '2000-01-01';
                $to = $now->format('Y-m-d');
                break;
            case 'custom':
                $from = $this->request->getQuery('from', $now->format('Y-m-01'));
                $to = $this->request->getQuery('to', $now->format('Y-m-d'));
                break;
            case 'month':
            default:
                $period = 'month';
                $from = $now->format('Y-m-01');
                $to = $now->format('Y-m-d');
                break;
        }

        return [$period, $from, $to];
    }
}

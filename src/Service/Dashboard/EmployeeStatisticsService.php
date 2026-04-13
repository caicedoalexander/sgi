<?php
declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Constants\ContractTypeConstants;
use App\Constants\EmployeeStatusConstants;
use App\Constants\NoveltyConstants;
use Cake\ORM\TableRegistry;
use Exception;

/**
 * Employee/RRHH-related dashboard statistics.
 */
class EmployeeStatisticsService
{
    /**
     * Get recent novelties for dashboard widget.
     *
     * @param int $limit Number of novelties to return.
     * @return array
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
     *
     * @return array
     */
    public function getBasicStats(): array
    {
        $stats = [];
        $stats['active_employees'] = $this->_safeCount(
            'Employees',
            ['employee_status_id' => EmployeeStatusConstants::ACTIVO],
        );

        $monthStart = date('Y-m-01 00:00:00');
        $stats['novelties_month'] = $this->_safeCount('EmployeeNovelties', ['created >=' => $monthStart]);

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
     *
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    public function getExtendedStats(string $from, string $to): array
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
     *
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    public function getChartData(string $from, string $to): array
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
        } catch (Exception $e) {
            return 0;
        }
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Dashboard\EmployeeStatisticsService;
use App\Service\Dashboard\InvoiceStatisticsService;
use Cake\ORM\TableRegistry;
use Exception;

/**
 * Facade for domain-specific dashboard statistics services.
 */
class DashboardStatisticsService
{
    private InvoiceStatisticsService $invoiceStats;
    private EmployeeStatisticsService $employeeStats;

    /**
     * @param \App\Service\Dashboard\InvoiceStatisticsService|null $invoiceStats Invoice stats.
     * @param \App\Service\Dashboard\EmployeeStatisticsService|null $employeeStats Employee stats.
     */
    public function __construct(
        ?InvoiceStatisticsService $invoiceStats = null,
        ?EmployeeStatisticsService $employeeStats = null,
    ) {
        $this->invoiceStats = $invoiceStats ?? new InvoiceStatisticsService();
        $this->employeeStats = $employeeStats ?? new EmployeeStatisticsService();
    }

    /**
     * @return array
     */
    public function getInvoiceStats(): array
    {
        return $this->invoiceStats->getStats();
    }

    /**
     * @param int $limit Limit.
     * @return array
     */
    public function getRecentInvoices(int $limit = 5): array
    {
        return $this->invoiceStats->getRecent($limit);
    }

    /**
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    public function getInvoiceFinancialStats(string $from, string $to): array
    {
        return $this->invoiceStats->getFinancialStats($from, $to);
    }

    /**
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    public function getInvoiceChartData(string $from, string $to): array
    {
        return $this->invoiceStats->getChartData($from, $to);
    }

    /**
     * @param int $limit Limit.
     * @return array
     */
    public function getRecentNovelties(int $limit = 5): array
    {
        return $this->employeeStats->getRecentNovelties($limit);
    }

    /**
     * @return array
     */
    public function getRrhhBasicStats(): array
    {
        return $this->employeeStats->getBasicStats();
    }

    /**
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    public function getRrhhExtendedStats(string $from, string $to): array
    {
        return $this->employeeStats->getExtendedStats($from, $to);
    }

    /**
     * @param string $from Start date.
     * @param string $to End date.
     * @return array
     */
    public function getRrhhChartData(string $from, string $to): array
    {
        return $this->employeeStats->getChartData($from, $to);
    }

    /**
     * @return array
     */
    public function getCatalogStats(): array
    {
        return [
            'providers' => $this->_safeCount('Providers', ['active' => true]),
            'operation_centers' => $this->_safeCount('OperationCenters'),
            'expense_types' => $this->_safeCount('ExpenseTypes'),
            'cost_centers' => $this->_safeCount('CostCenters'),
        ];
    }

    /**
     * @return array
     */
    public function getAdminStats(): array
    {
        return [
            'users' => $this->_safeCount('Users', ['active' => true]),
            'roles' => $this->_safeCount('Roles'),
        ];
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

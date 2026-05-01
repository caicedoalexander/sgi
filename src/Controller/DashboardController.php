<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\DashboardStatisticsService;
use DateTime;

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

        $stats = $this->getContainer()->get(DashboardStatisticsService::class);

        // --- Facturacion ---
        $invoiceStats = [];
        $recentInvoices = [];
        $invoiceFinancialStats = [];
        $invoiceChartData = [];
        if ($canView('invoices')) {
            $invoiceStats = $stats->getInvoiceStats();
            $recentInvoices = $stats->getRecentInvoices();
            $invoiceFinancialStats = $stats->getInvoiceFinancialStats($dateFrom, $dateTo);
            $invoiceChartData = $stats->getInvoiceChartData($dateFrom, $dateTo);
        }

        // --- RRHH ---
        $rrhhStats = [];
        $recentNovelties = [];
        $rrhhExtendedStats = [];
        $rrhhChartData = [];
        if ($canView('employees') || $canView('employee_novelties')) {
            $rrhhStats = $stats->getRrhhBasicStats();
            $recentNovelties = $stats->getRecentNovelties();
            $rrhhExtendedStats = $stats->getRrhhExtendedStats($dateFrom, $dateTo);
            $rrhhChartData = $stats->getRrhhChartData($dateFrom, $dateTo);
        }

        // --- Catalogos ---
        $catalogStats = $canView('providers') || $canView('operation_centers')
            || $canView('expense_types') || $canView('cost_centers')
            ? $stats->getCatalogStats()
            : [];

        // --- Administracion ---
        $adminStats = $canView('users') || $canView('roles')
            ? $stats->getAdminStats()
            : [];

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

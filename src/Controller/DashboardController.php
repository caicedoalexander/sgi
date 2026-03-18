<?php
declare(strict_types=1);

namespace App\Controller;

use Exception;

class DashboardController extends AppController
{
    public function index()
    {
        $identity = $this->Authentication->getIdentity();
        if (!$identity) {
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        $userPermissions = $this->viewBuilder()->getVar('userPermissions') ?? [];
        $canView = fn(string $module): bool => !empty($userPermissions[$module]['can_view']);

        // --- Facturación ---
        $invoiceStats = [];
        $recentInvoices = [];
        if ($canView('invoices')) {
            $invoiceStats = [
                'total'         => $this->_safeCount('Invoices'),
                'aprobacion'    => $this->_safeCount('Invoices', ['pipeline_status' => 'aprobacion']),
                'contabilidad'  => $this->_safeCount('Invoices', ['pipeline_status' => 'contabilidad']),
                'tesoreria'     => $this->_safeCount('Invoices', ['pipeline_status' => 'tesoreria']),
                'pagada'        => $this->_safeCount('Invoices', ['pipeline_status' => 'pagada']),
                'rechazada'     => $this->_safeCount('Invoices', ['area_approval' => 'Rechazada']),
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
        }

        // --- RRHH ---
        $rrhhStats = [];
        $recentNovelties = [];
        if ($canView('employees') || $canView('employee_novelties')) {
            if ($canView('employees')) {
                $rrhhStats['active_employees'] = $this->_safeCount('Employees', ['active' => true]);
            }
            if ($canView('employee_novelties')) {
                $monthStart = date('Y-m-01 00:00:00');
                $rrhhStats['novelties_month'] = $this->_safeCount('EmployeeNovelties', ['created >=' => $monthStart]);
                $recentNovelties = $this->_safeQuery(function () {
                    return $this->fetchTable('EmployeeNovelties')
                        ->find()
                        ->select(['id', 'employee_id', 'novelty_type_id', 'created'])
                        ->contain([
                            'Employees'    => ['fields' => ['id', 'first_name', 'last_name1', 'last_name2']],
                            'NoveltyTypes' => ['fields' => ['id', 'name']],
                        ])
                        ->orderByDesc('created')
                        ->limit(5)
                        ->toArray();
                });
            }
        }

        // --- Catálogos ---
        $catalogStats = [];
        if ($canView('providers'))          $catalogStats['providers']          = $this->_safeCount('Providers', ['active' => true]);
        if ($canView('operation_centers'))  $catalogStats['operation_centers']  = $this->_safeCount('OperationCenters');
        if ($canView('expense_types'))      $catalogStats['expense_types']      = $this->_safeCount('ExpenseTypes');
        if ($canView('cost_centers'))       $catalogStats['cost_centers']       = $this->_safeCount('CostCenters');

        // --- Administración ---
        $adminStats = [];
        if ($canView('users')) $adminStats['users'] = $this->_safeCount('Users', ['active' => true]);
        if ($canView('roles')) $adminStats['roles'] = $this->_safeCount('Roles');

        $this->set(compact(
            'invoiceStats', 'recentInvoices',
            'rrhhStats', 'recentNovelties',
            'catalogStats',
            'adminStats'
        ));
    }

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

    private function _safeQuery(callable $fn): array
    {
        try {
            return $fn();
        } catch (Exception $e) {
            return [];
        }
    }
}

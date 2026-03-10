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

        $counters = [
            'invoices' => $this->_safeCount('Invoices'),
            'employees' => $this->_safeCount('Employees', ['active' => true]),
            'providers' => $this->_safeCount('Providers', ['active' => true]),
            'users' => $this->_safeCount('Users', ['active' => true]),
        ];

        $this->set(compact('counters'));
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
}

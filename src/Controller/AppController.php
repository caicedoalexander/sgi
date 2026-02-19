<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthorizationService;
use Cake\Controller\Controller;
use Cake\ORM\TableRegistry;

class AppController extends Controller
{
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');
    }

    public function beforeFilter(\Cake\Event\EventInterface $event): void
    {
        parent::beforeFilter($event);

        $identity = $this->Authentication->getIdentity();

        // Pass current user to all views
        $this->set('currentUser', $identity?->getOriginalData());

        // Set sidebar counters
        if ($identity) {
            $this->_setSidebarCounters($identity->getOriginalData());
        }
    }

    protected function _setSidebarCounters(object $user): void
    {
        try {
            $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
            $roleName = $user->role->name ?? '';

            $authService = new AuthorizationService();
            $visibleStatuses = $this->_getVisibleStatuses($roleName);

            $counters = [];
            foreach ($visibleStatuses as $status) {
                $counters[$status] = $invoicesTable->find()
                    ->where(['pipeline_status' => $status])
                    ->count();
            }

            $this->set('sidebarCounters', $counters);
        } catch (\Exception $e) {
            $this->set('sidebarCounters', []);
        }
    }

    protected function _getVisibleStatuses(string $roleName): array
    {
        return match ($roleName) {
            'Registro/Revisión' => ['revision'],
            'Contabilidad' => ['area_approved', 'accrued'],
            'Tesorería' => ['treasury'],
            'Admin' => ['revision', 'area_approved', 'accrued', 'treasury', 'paid'],
            default => [],
        };
    }

    protected function _requireAuth(): void
    {
        $identity = $this->Authentication->getIdentity();
        if (!$identity) {
            $this->Flash->error('Debe iniciar sesión para acceder.');
            $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }
    }

    protected function _checkPermission(string $module, string $action): bool
    {
        $identity = $this->Authentication->getIdentity();
        if (!$identity) {
            return false;
        }

        $user = $identity->getOriginalData();
        $roleName = $user->role->name ?? '';

        // Admin always allowed
        if ($roleName === AuthorizationService::ROLE_ADMIN) {
            return true;
        }

        $authService = new AuthorizationService();
        return $authService->isAllowed($user->role_id, $roleName, $module, $action);
    }
}

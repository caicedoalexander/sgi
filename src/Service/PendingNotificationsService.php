<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RoleConstants;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;

/**
 * Agrega los pendientes por usuario para enviar resúmenes externos
 * (cron de notificaciones cada hora vía n8n).
 *
 * Reusa SidebarCounterService como única fuente de verdad de "qué cuenta
 * como pendiente para este rol", para que la lógica no se duplique.
 */
class PendingNotificationsService
{
    /**
     * @param \App\Service\SidebarCounterService $counterService Sidebar counters reused as source of truth.
     * @param \App\Service\PaymentSchedulingPipelineService $paymentSchedulingService Payment scheduling visibility resolver.
     */
    public function __construct(
        private readonly SidebarCounterService $counterService,
        private readonly PaymentSchedulingPipelineService $paymentSchedulingService,
    ) {
    }

    /**
     * Lista de usuarios activos con al menos 1 pendiente.
     *
     * @return array<int, array{user_id:int, name:string, email:string, role:string, total:int, modules:array<int, array{key:string, label:string, count:int, url:string}>}>
     */
    public function getPendingByUser(): array
    {
        $users = TableRegistry::getTableLocator()->get('Users')->find()
            ->contain(['Roles'])
            ->where([
                'Users.active' => true,
                'Users.email IS NOT' => null,
                'Users.email !=' => '',
            ])
            ->all();

        $result = [];
        foreach ($users as $user) {
            $roleName = $user->role->name ?? '';
            if ($roleName === '' || $roleName === RoleConstants::ADMIN) {
                continue;
            }
            $roleId = (int)($user->role_id ?? $user->role->id ?? 0);
            if ($roleId === 0) {
                continue;
            }

            $modules = $this->_buildModules($roleId);
            $total = array_sum(array_column($modules, 'count'));
            if ($total < 1) {
                continue;
            }

            $result[] = [
                'user_id' => (int)$user->id,
                'name' => (string)($user->full_name ?? $user->username),
                'email' => (string)$user->email,
                'role' => $roleName,
                'total' => $total,
                'modules' => $modules,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{key:string, label:string, count:int, url:string}>
     */
    private function _buildModules(int $roleId): array
    {
        $counters = $this->counterService->getCounters($roleId);
        $invoicesPending = (int)array_sum($counters['sidebarCounters'] ?? []);
        $paymentSchedulings = $this->_getPaymentSchedulingsCount($roleId);

        $raw = [
            [
                'key' => 'invoices',
                'label' => 'Facturas',
                'count' => $invoicesPending,
                'route' => ['controller' => 'Invoices', 'action' => 'index'],
            ],
            [
                'key' => 'advances',
                'label' => 'Anticipos',
                'count' => (int)($counters['advancesMineCount'] ?? 0),
                'route' => ['controller' => 'Advances', 'action' => 'index'],
            ],
            [
                'key' => 'petty_cash',
                'label' => 'Caja Menor',
                'count' => (int)($counters['pettyCashMineCount'] ?? 0),
                'route' => ['controller' => 'PettyCashRecords', 'action' => 'index'],
            ],
            [
                'key' => 'refunds',
                'label' => 'Reintegros',
                'count' => (int)($counters['refundsMineCount'] ?? 0),
                'route' => ['controller' => 'Refunds', 'action' => 'index'],
            ],
            [
                'key' => 'novelties',
                'label' => 'Novedades',
                'count' => (int)($counters['noveltiesCount'] ?? 0),
                'route' => ['controller' => 'EmployeeNovelties', 'action' => 'index'],
            ],
            [
                'key' => 'liquidations',
                'label' => 'Liquidación de Novedades',
                'count' => (int)($counters['liquidationMineCount'] ?? 0),
                'route' => ['controller' => 'NoveltyLiquidationDocs', 'action' => 'index'],
            ],
            [
                'key' => 'payment_schedulings',
                'label' => 'Programación de Pagos',
                'count' => $paymentSchedulings,
                'route' => ['controller' => 'PaymentSchedulings', 'action' => 'index'],
            ],
        ];

        $base = rtrim((string)Configure::read('App.fullBaseUrl'), '/');
        $modules = [];
        foreach ($raw as $row) {
            if ($row['count'] < 1) {
                continue;
            }
            $modules[] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'count' => $row['count'],
                'url' => $base . Router::url($row['route']),
            ];
        }

        return $modules;
    }

    /**
     * Count payment schedulings visible to the current role.
     */
    private function _getPaymentSchedulingsCount(int $roleId): int
    {
        $visibleStatuses = $this->paymentSchedulingService->getVisibleStatuses($roleId);
        if (empty($visibleStatuses)) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('PaymentSchedulings')->find()
            ->where(['pipeline_status IN' => $visibleStatuses])
            ->count();
    }
}

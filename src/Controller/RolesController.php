<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Constants\PipelineStepConstants;
use App\Constants\RoleConstants;
use App\Service\AuthorizationService;
use App\Service\PipelineAuthorizationService;

class RolesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private PipelineAuthorizationService $pipelineAuth;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        // PA-004: dependencia directa a PipelineAuthorizationService legal aquí
        // porque RolesController consume matrices y save*, que quedan fuera del
        // contrato de AuthorizationFacade. El resto del código usa el Facade.
        $this->pipelineAuth = $this->getContainer()->get(PipelineAuthorizationService::class);
    }

    /**
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $roles = $this->paginate($this->Roles->find()->contain(['Users']));

        $moduleTotal = count(AuthorizationService::MODULES);
        $pipelineTotal = array_sum(array_map('count', PipelineStepConstants::STEPS_BY_PIPELINE));

        // Resumen de permisos y usuarios por cada rol de la página.
        $roleStats = [];
        foreach ($roles as $role) {
            $modMatrix = $this->authService->getPermissionsForRoleAsMatrix($role->id);
            $moduleCount = 0;
            foreach ($modMatrix as $perm) {
                if (
                    !empty($perm['can_view']) || !empty($perm['can_create'])
                    || !empty($perm['can_edit']) || !empty($perm['can_delete'])
                ) {
                    $moduleCount++;
                }
            }
            $pipelineCount = 0;
            foreach ($this->pipelineAuth->getPermissionsMatrix($role->id) as $steps) {
                $pipelineCount += count(array_filter($steps));
            }
            $userNames = array_map(static fn($u) => $u->full_name, $role->users ?? []);

            $roleStats[$role->id] = [
                'moduleCount' => $moduleCount,
                'pipelineCount' => $pipelineCount,
                'userCount' => count($userNames),
                'userNames' => $userNames,
                'isSystem' => RoleConstants::isSystem($role->name),
            ];
        }

        // KPIs y contadores de filtro sobre la totalidad de roles.
        $allNames = $this->Roles->find()->select(['name'])->all()->extract('name')->toList();
        $systemCount = count(array_filter($allNames, RoleConstants::isSystem(...)));
        $kpis = [
            'roleCount' => count($allNames),
            'customCount' => count($allNames) - $systemCount,
            'systemCount' => $systemCount,
            'userCount' => $this->Roles->Users->find()->where(['role_id IS NOT' => null])->count(),
            'moduleTotal' => $moduleTotal,
            'pipelineTotal' => $pipelineTotal,
        ];

        $this->set(compact('roles', 'roleStats', 'kpis', 'moduleTotal', 'pipelineTotal'));
    }

    /**
     * @return void
     */
    #[Permission(action: 'view')]
    public function view($id = null)
    {
        $role = $this->Roles->get($id, contain: ['Users', 'Permissions']);
        $pipelineMatrix = $this->pipelineAuth->getPermissionsMatrix((int)$id);
        $pipelineLabels = PipelineStepConstants::PIPELINE_LABELS;
        $stepLabels = PipelineStepConstants::STEP_LABELS;

        $this->set(compact('role', 'pipelineMatrix', 'pipelineLabels', 'stepLabels'));
    }

    /**
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add()
    {
        $role = $this->Roles->newEmptyEntity();
        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $role = $this->Roles->patchEntity($role, $data);
            if ($this->Roles->save($role)) {
                $connection = $this->Roles->getConnection();
                $connection->transactional(function () use ($role, $data): void {
                    if (!empty($data['permissions'])) {
                        $this->authService->savePermissionsForRole($role->id, $data['permissions']);
                    }
                    $this->pipelineAuth->savePermissions($role->id, $data['pipeline_permissions'] ?? []);
                });
                $this->Flash->success('El rol ha sido guardado.');

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('No se pudo guardar el rol. Intente de nuevo.');
        }

        $modules = AuthorizationService::MODULES;
        $moduleGroups = AuthorizationService::MODULE_GROUPS;
        $permissionsMatrix = $this->authService->getPermissionsForRoleAsMatrix(0);
        $pipelineMatrix = $this->pipelineAuth->getEmptyMatrix();
        $pipelineLabels = PipelineStepConstants::PIPELINE_LABELS;
        $stepLabels = PipelineStepConstants::STEP_LABELS;
        $userCount = 0;
        $isSystem = false;

        $this->set(compact(
            'role',
            'modules',
            'moduleGroups',
            'permissionsMatrix',
            'pipelineMatrix',
            'pipelineLabels',
            'stepLabels',
            'userCount',
            'isSystem',
        ));

        return null;
    }

    /**
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $role = $this->Roles->get($id, contain: ['Permissions']);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();
            $role = $this->Roles->patchEntity($role, $data);
            if ($this->Roles->save($role)) {
                $connection = $this->Roles->getConnection();
                $connection->transactional(function () use ($role, $data): void {
                    $this->authService->savePermissionsForRole($role->id, $data['permissions'] ?? []);
                    $this->pipelineAuth->savePermissions($role->id, $data['pipeline_permissions'] ?? []);
                });
                $this->Flash->success('El rol ha sido actualizado.');

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('No se pudo actualizar el rol. Intente de nuevo.');
        }

        $modules = AuthorizationService::MODULES;
        $moduleGroups = AuthorizationService::MODULE_GROUPS;
        $permissionsMatrix = $this->authService->getPermissionsForRoleAsMatrix((int)$id);
        $pipelineMatrix = $this->pipelineAuth->getPermissionsMatrix((int)$id);
        $pipelineLabels = PipelineStepConstants::PIPELINE_LABELS;
        $stepLabels = PipelineStepConstants::STEP_LABELS;
        $userCount = $this->Roles->Users->find()->where(['role_id' => (int)$id])->count();
        $isSystem = RoleConstants::isSystem($role->name);

        $this->set(compact(
            'role',
            'modules',
            'moduleGroups',
            'permissionsMatrix',
            'pipelineMatrix',
            'pipelineLabels',
            'stepLabels',
            'userCount',
            'isSystem',
        ));

        return null;
    }

    /**
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $role = $this->Roles->get($id);
        if (RoleConstants::isSystem($role->name)) {
            $this->Flash->error('Los roles del sistema no pueden eliminarse.');

            return $this->redirect(['action' => 'index']);
        }
        if ($this->Roles->delete($role)) {
            $this->Flash->success('El rol ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el rol. Intente de nuevo.');
        }

        return $this->redirect(['action' => 'index']);
    }
}

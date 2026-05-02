<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\PipelineStepConstants;
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
        $this->pipelineAuth = $this->getContainer()->get(PipelineAuthorizationService::class);
    }

    /**
     * @return void
     */
    public function index()
    {
        $roles = $this->paginate($this->Roles);

        $this->set(compact('roles'));
    }

    /**
     * @return void
     */
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
        $permissionsMatrix = [];
        $pipelineMatrix = $this->pipelineAuth->getPermissionsMatrix(0);
        $pipelineLabels = PipelineStepConstants::PIPELINE_LABELS;
        $stepLabels = PipelineStepConstants::STEP_LABELS;

        $this->set(compact('role', 'modules', 'permissionsMatrix', 'pipelineMatrix', 'pipelineLabels', 'stepLabels'));

        return null;
    }

    /**
     * @return \Cake\Http\Response|null
     */
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
        $permissionsMatrix = $this->authService->getPermissionsForRoleAsMatrix((int)$id);
        $pipelineMatrix = $this->pipelineAuth->getPermissionsMatrix((int)$id);
        $pipelineLabels = PipelineStepConstants::PIPELINE_LABELS;
        $stepLabels = PipelineStepConstants::STEP_LABELS;

        $this->set(compact('role', 'modules', 'permissionsMatrix', 'pipelineMatrix', 'pipelineLabels', 'stepLabels'));

        return null;
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $role = $this->Roles->get($id);
        if ($this->Roles->delete($role)) {
            $this->Flash->success('El rol ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el rol. Intente de nuevo.');
        }

        return $this->redirect(['action' => 'index']);
    }
}

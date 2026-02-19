<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthorizationService;

class RolesController extends AppController
{
    public function index()
    {
        $roles = $this->paginate($this->Roles);

        $this->set(compact('roles'));
    }

    public function view($id = null)
    {
        $role = $this->Roles->get($id, contain: ['Users', 'Permissions']);

        $this->set(compact('role'));
    }

    public function add()
    {
        $role = $this->Roles->newEmptyEntity();
        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $role = $this->Roles->patchEntity($role, $data);
            if ($this->Roles->save($role)) {
                // Save permissions if provided
                if (!empty($data['permissions'])) {
                    $authService = new AuthorizationService();
                    $authService->savePermissionsForRole($role->id, $data['permissions']);
                }
                $this->Flash->success('El rol ha sido guardado.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('No se pudo guardar el rol. Intente de nuevo.');
        }

        $modules = AuthorizationService::MODULES;
        $permissionsMatrix = [];

        $this->set(compact('role', 'modules', 'permissionsMatrix'));
    }

    public function edit($id = null)
    {
        $role = $this->Roles->get($id, contain: ['Permissions']);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();
            $role = $this->Roles->patchEntity($role, $data);
            if ($this->Roles->save($role)) {
                // Save permissions
                $authService = new AuthorizationService();
                $authService->savePermissionsForRole($role->id, $data['permissions'] ?? []);
                $this->Flash->success('El rol ha sido actualizado.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('No se pudo actualizar el rol. Intente de nuevo.');
        }

        $authService = new AuthorizationService();
        $modules = AuthorizationService::MODULES;
        $permissionsMatrix = $authService->getPermissionsForRoleAsMatrix($id);

        $this->set(compact('role', 'modules', 'permissionsMatrix'));
    }

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

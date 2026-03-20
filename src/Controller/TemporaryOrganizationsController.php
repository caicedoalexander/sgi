<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ExcelCatalogTrait;

class TemporaryOrganizationsController extends AppController
{
    use ExcelCatalogTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function index()
    {
        $temporaryOrganizations = $this->paginate($this->TemporaryOrganizations);

        $this->set(compact('temporaryOrganizations'));
    }

    public function add()
    {
        $temporaryOrganization = $this->TemporaryOrganizations->newEmptyEntity();
        if ($this->request->is('post')) {
            $temporaryOrganization = $this->TemporaryOrganizations->patchEntity($temporaryOrganization, $this->request->getData());
            if ($this->TemporaryOrganizations->save($temporaryOrganization)) {
                $this->Flash->success(__('La organización temporal ha sido guardada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar la organización temporal. Intente de nuevo.'));
        }

        $this->set(compact('temporaryOrganization'));
    }

    public function edit($id = null)
    {
        $temporaryOrganization = $this->TemporaryOrganizations->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $temporaryOrganization = $this->TemporaryOrganizations->patchEntity($temporaryOrganization, $this->request->getData());
            if ($this->TemporaryOrganizations->save($temporaryOrganization)) {
                $this->Flash->success(__('La organización temporal ha sido actualizada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar la organización temporal. Intente de nuevo.'));
        }

        $this->set(compact('temporaryOrganization'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $temporaryOrganization = $this->TemporaryOrganizations->get($id);
        if ($this->TemporaryOrganizations->delete($temporaryOrganization)) {
            $this->Flash->success(__('La organización temporal ha sido eliminada.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar la organización temporal. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

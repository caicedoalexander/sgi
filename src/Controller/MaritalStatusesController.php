<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ExcelCatalogTrait;

class MaritalStatusesController extends AppController
{
    use ExcelCatalogTrait;
    public function index()
    {
        $maritalStatuses = $this->paginate($this->MaritalStatuses);

        $this->set(compact('maritalStatuses'));
    }

    public function view($id = null)
    {
        $maritalStatus = $this->MaritalStatuses->get($id);

        $this->set(compact('maritalStatus'));
    }

    public function add()
    {
        $maritalStatus = $this->MaritalStatuses->newEmptyEntity();
        if ($this->request->is('post')) {
            $maritalStatus = $this->MaritalStatuses->patchEntity($maritalStatus, $this->request->getData());
            if ($this->MaritalStatuses->save($maritalStatus)) {
                $this->Flash->success(__('El estado civil ha sido guardado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar el estado civil. Intente de nuevo.'));
        }

        $this->set(compact('maritalStatus'));
    }

    public function edit($id = null)
    {
        $maritalStatus = $this->MaritalStatuses->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $maritalStatus = $this->MaritalStatuses->patchEntity($maritalStatus, $this->request->getData());
            if ($this->MaritalStatuses->save($maritalStatus)) {
                $this->Flash->success(__('El estado civil ha sido actualizado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar el estado civil. Intente de nuevo.'));
        }

        $this->set(compact('maritalStatus'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $maritalStatus = $this->MaritalStatuses->get($id);
        if ($this->MaritalStatuses->delete($maritalStatus)) {
            $this->Flash->success(__('El estado civil ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el estado civil. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

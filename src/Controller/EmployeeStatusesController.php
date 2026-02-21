<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ExcelCatalogTrait;

class EmployeeStatusesController extends AppController
{
    use ExcelCatalogTrait;
    public function index()
    {
        $employeeStatuses = $this->paginate($this->EmployeeStatuses);

        $this->set(compact('employeeStatuses'));
    }

    public function view($id = null)
    {
        $employeeStatus = $this->EmployeeStatuses->get($id);

        $this->set(compact('employeeStatus'));
    }

    public function add()
    {
        $employeeStatus = $this->EmployeeStatuses->newEmptyEntity();
        if ($this->request->is('post')) {
            $employeeStatus = $this->EmployeeStatuses->patchEntity($employeeStatus, $this->request->getData());
            if ($this->EmployeeStatuses->save($employeeStatus)) {
                $this->Flash->success(__('El estado ha sido guardado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar el estado. Intente de nuevo.'));
        }

        $this->set(compact('employeeStatus'));
    }

    public function edit($id = null)
    {
        $employeeStatus = $this->EmployeeStatuses->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $employeeStatus = $this->EmployeeStatuses->patchEntity($employeeStatus, $this->request->getData());
            if ($this->EmployeeStatuses->save($employeeStatus)) {
                $this->Flash->success(__('El estado ha sido actualizado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar el estado. Intente de nuevo.'));
        }

        $this->set(compact('employeeStatus'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $employeeStatus = $this->EmployeeStatuses->get($id);
        if ($this->EmployeeStatuses->delete($employeeStatus)) {
            $this->Flash->success(__('El estado ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el estado. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

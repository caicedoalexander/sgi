<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ExcelWizardTrait;

class EmployeeStatusesController extends AppController
{
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista los estados de empleado.
     *
     * @return void
     */
    public function index()
    {
        $employeeStatuses = $this->paginate($this->EmployeeStatuses);

        $this->set(compact('employeeStatuses'));
    }

    /**
     * Muestra un estado de empleado.
     *
     * @param string|null $id Employee status id.
     * @return void
     */
    public function view(?string $id = null)
    {
        $employeeStatus = $this->EmployeeStatuses->get($id);

        $this->set(compact('employeeStatus'));
    }

    /**
     * Crea un estado de empleado.
     *
     * @return \Cake\Http\Response|null
     */
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

    /**
     * Edita un estado de empleado.
     *
     * @param string|null $id Employee status id.
     * @return \Cake\Http\Response|null
     */
    public function edit(?string $id = null)
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

    /**
     * Elimina un estado de empleado.
     *
     * @param string|null $id Employee status id.
     * @return \Cake\Http\Response|null
     */
    public function delete(?string $id = null)
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

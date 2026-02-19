<?php
declare(strict_types=1);

namespace App\Controller;

class CostCentersController extends AppController
{
    public function index()
    {
        $costCenters = $this->paginate($this->CostCenters);

        $this->set(compact('costCenters'));
    }

    public function view($id = null)
    {
        $costCenter = $this->CostCenters->get($id, contain: ['Invoices']);

        $this->set(compact('costCenter'));
    }

    public function add()
    {
        $costCenter = $this->CostCenters->newEmptyEntity();
        if ($this->request->is('post')) {
            $costCenter = $this->CostCenters->patchEntity($costCenter, $this->request->getData());
            if ($this->CostCenters->save($costCenter)) {
                $this->Flash->success(__('El centro de costos ha sido guardado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar el centro de costos. Intente de nuevo.'));
        }

        $this->set(compact('costCenter'));
    }

    public function edit($id = null)
    {
        $costCenter = $this->CostCenters->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $costCenter = $this->CostCenters->patchEntity($costCenter, $this->request->getData());
            if ($this->CostCenters->save($costCenter)) {
                $this->Flash->success(__('El centro de costos ha sido actualizado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar el centro de costos. Intente de nuevo.'));
        }

        $this->set(compact('costCenter'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $costCenter = $this->CostCenters->get($id);
        if ($this->CostCenters->delete($costCenter)) {
            $this->Flash->success(__('El centro de costos ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el centro de costos. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

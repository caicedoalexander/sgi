<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ExcelCatalogTrait;

class OperationCentersController extends AppController
{
    use ExcelCatalogTrait;
    public function index()
    {
        $operationCenters = $this->paginate($this->OperationCenters);

        $this->set(compact('operationCenters'));
    }

    public function view($id = null)
    {
        $operationCenter = $this->OperationCenters->get($id, contain: ['Invoices']);

        $this->set(compact('operationCenter'));
    }

    public function add()
    {
        $operationCenter = $this->OperationCenters->newEmptyEntity();
        if ($this->request->is('post')) {
            $operationCenter = $this->OperationCenters->patchEntity($operationCenter, $this->request->getData());
            if ($this->OperationCenters->save($operationCenter)) {
                $this->Flash->success(__('El centro de operación ha sido guardado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar el centro de operación. Intente de nuevo.'));
        }

        $this->set(compact('operationCenter'));
    }

    public function edit($id = null)
    {
        $operationCenter = $this->OperationCenters->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $operationCenter = $this->OperationCenters->patchEntity($operationCenter, $this->request->getData());
            if ($this->OperationCenters->save($operationCenter)) {
                $this->Flash->success(__('El centro de operación ha sido actualizado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar el centro de operación. Intente de nuevo.'));
        }

        $this->set(compact('operationCenter'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $operationCenter = $this->OperationCenters->get($id);
        if ($this->OperationCenters->delete($operationCenter)) {
            $this->Flash->success(__('El centro de operación ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el centro de operación. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ExcelCatalogTrait;

class PositionsController extends AppController
{
    use ExcelCatalogTrait;
    public function index()
    {
        $positions = $this->paginate($this->Positions);

        $this->set(compact('positions'));
    }

    public function view($id = null)
    {
        $position = $this->Positions->get($id);

        $this->set(compact('position'));
    }

    public function add()
    {
        $position = $this->Positions->newEmptyEntity();
        if ($this->request->is('post')) {
            $position = $this->Positions->patchEntity($position, $this->request->getData());
            if ($this->Positions->save($position)) {
                $this->Flash->success(__('El cargo ha sido guardado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar el cargo. Intente de nuevo.'));
        }

        $this->set(compact('position'));
    }

    public function edit($id = null)
    {
        $position = $this->Positions->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $position = $this->Positions->patchEntity($position, $this->request->getData());
            if ($this->Positions->save($position)) {
                $this->Flash->success(__('El cargo ha sido actualizado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar el cargo. Intente de nuevo.'));
        }

        $this->set(compact('position'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $position = $this->Positions->get($id);
        if ($this->Positions->delete($position)) {
            $this->Flash->success(__('El cargo ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el cargo. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

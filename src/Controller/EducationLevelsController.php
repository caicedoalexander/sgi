<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ExcelCatalogTrait;

class EducationLevelsController extends AppController
{
    use ExcelCatalogTrait;
    public function index()
    {
        $educationLevels = $this->paginate($this->EducationLevels);

        $this->set(compact('educationLevels'));
    }

    public function view($id = null)
    {
        $educationLevel = $this->EducationLevels->get($id);

        $this->set(compact('educationLevel'));
    }

    public function add()
    {
        $educationLevel = $this->EducationLevels->newEmptyEntity();
        if ($this->request->is('post')) {
            $educationLevel = $this->EducationLevels->patchEntity($educationLevel, $this->request->getData());
            if ($this->EducationLevels->save($educationLevel)) {
                $this->Flash->success(__('El nivel educativo ha sido guardado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar el nivel educativo. Intente de nuevo.'));
        }

        $this->set(compact('educationLevel'));
    }

    public function edit($id = null)
    {
        $educationLevel = $this->EducationLevels->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $educationLevel = $this->EducationLevels->patchEntity($educationLevel, $this->request->getData());
            if ($this->EducationLevels->save($educationLevel)) {
                $this->Flash->success(__('El nivel educativo ha sido actualizado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar el nivel educativo. Intente de nuevo.'));
        }

        $this->set(compact('educationLevel'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $educationLevel = $this->EducationLevels->get($id);
        if ($this->EducationLevels->delete($educationLevel)) {
            $this->Flash->success(__('El nivel educativo ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el nivel educativo. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

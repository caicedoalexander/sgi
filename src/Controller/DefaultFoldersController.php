<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ExcelCatalogTrait;

class DefaultFoldersController extends AppController
{
    use ExcelCatalogTrait;
    public function index()
    {
        $defaultFolders = $this->paginate($this->DefaultFolders, ['order' => ['sort_order' => 'ASC']]);

        $this->set(compact('defaultFolders'));
    }

    public function view($id = null)
    {
        $defaultFolder = $this->DefaultFolders->get($id);

        $this->set(compact('defaultFolder'));
    }

    public function add()
    {
        $defaultFolder = $this->DefaultFolders->newEmptyEntity();
        if ($this->request->is('post')) {
            $defaultFolder = $this->DefaultFolders->patchEntity($defaultFolder, $this->request->getData());
            if ($this->DefaultFolders->save($defaultFolder)) {
                $this->Flash->success(__('La carpeta por defecto ha sido guardada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar la carpeta por defecto. Intente de nuevo.'));
        }

        $this->set(compact('defaultFolder'));
    }

    public function edit($id = null)
    {
        $defaultFolder = $this->DefaultFolders->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $defaultFolder = $this->DefaultFolders->patchEntity($defaultFolder, $this->request->getData());
            if ($this->DefaultFolders->save($defaultFolder)) {
                $this->Flash->success(__('La carpeta por defecto ha sido actualizada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar la carpeta por defecto. Intente de nuevo.'));
        }

        $this->set(compact('defaultFolder'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $defaultFolder = $this->DefaultFolders->get($id);
        if ($this->DefaultFolders->delete($defaultFolder)) {
            $this->Flash->success(__('La carpeta por defecto ha sido eliminada.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar la carpeta por defecto. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

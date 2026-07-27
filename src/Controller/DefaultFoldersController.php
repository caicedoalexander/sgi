<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;
use App\Controller\Trait\ExcelWizardTrait;

class DefaultFoldersController extends AppController
{
    use CatalogCrudTrait;
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista las carpetas por defecto.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $defaultFolders = $this->paginate($this->DefaultFolders, ['order' => ['sort_order' => 'ASC']]);

        $this->set(compact('defaultFolders'));
    }

    /**
     * Muestra una carpeta por defecto.
     *
     * @param string|null $id Default folder id.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null)
    {
        $defaultFolder = $this->DefaultFolders->get($id);

        $this->set(compact('defaultFolder'));
    }

    /**
     * Crea una carpeta por defecto.
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add()
    {
        $defaultFolder = $this->DefaultFolders->newEmptyEntity();
        $result = $this->_catalogSave(
            $this->DefaultFolders,
            $defaultFolder,
            __('La carpeta por defecto ha sido guardada.'),
            __('No se pudo guardar la carpeta por defecto. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('defaultFolder'));
    }

    /**
     * Edita una carpeta por defecto.
     *
     * @param string|null $id Default folder id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
    {
        $defaultFolder = $this->DefaultFolders->get($id);
        $result = $this->_catalogSave(
            $this->DefaultFolders,
            $defaultFolder,
            __('La carpeta por defecto ha sido actualizada.'),
            __('No se pudo actualizar la carpeta por defecto. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('defaultFolder'));
    }

    /**
     * Elimina una carpeta por defecto.
     *
     * @param string|null $id Default folder id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
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

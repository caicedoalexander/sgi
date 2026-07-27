<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;

class AssetCategoriesController extends AppController
{
    use CatalogCrudTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista las categorías de activos.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $assetCategories = $this->paginate($this->AssetCategories->find()->orderBy(['AssetCategories.name' => 'ASC']));

        $this->set(compact('assetCategories'));
    }

    /**
     * Muestra una categoría.
     *
     * @param string|null $id Category id.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null)
    {
        $assetCategory = $this->AssetCategories->get($id);

        $this->set(compact('assetCategory'));
    }

    /**
     * Crea una categoría (soporta modal AJAX).
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add()
    {
        $assetCategory = $this->AssetCategories->newEmptyEntity();
        $result = $this->_catalogSave(
            $this->AssetCategories,
            $assetCategory,
            __('La categoría ha sido guardada.'),
            __('No se pudo guardar la categoría. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('assetCategory'));
    }

    /**
     * Edita una categoría (soporta modal AJAX).
     *
     * @param string|null $id Category id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
    {
        $assetCategory = $this->AssetCategories->get($id);
        $result = $this->_catalogSave(
            $this->AssetCategories,
            $assetCategory,
            __('La categoría ha sido actualizada.'),
            __('No se pudo actualizar la categoría. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('assetCategory'));
    }

    /**
     * Elimina una categoría.
     *
     * @param string|null $id Category id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $assetCategory = $this->AssetCategories->get($id);
        if ($this->AssetCategories->delete($assetCategory)) {
            $this->Flash->success(__('La categoría ha sido eliminada.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar la categoría. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

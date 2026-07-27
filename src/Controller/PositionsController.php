<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;
use App\Controller\Trait\ExcelWizardTrait;

class PositionsController extends AppController
{
    use CatalogCrudTrait;
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista los cargos.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $positions = $this->paginate($this->Positions);

        $this->set(compact('positions'));
    }

    /**
     * Muestra un cargo.
     *
     * @param string|null $id Position id.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null)
    {
        $position = $this->Positions->get($id);

        $this->set(compact('position'));
    }

    /**
     * Crea un cargo.
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add()
    {
        $position = $this->Positions->newEmptyEntity();
        $result = $this->_catalogSave(
            $this->Positions,
            $position,
            __('El cargo ha sido guardado.'),
            __('No se pudo guardar el cargo. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('position'));
    }

    /**
     * Edita un cargo.
     *
     * @param string|null $id Position id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
    {
        $position = $this->Positions->get($id);
        $result = $this->_catalogSave(
            $this->Positions,
            $position,
            __('El cargo ha sido actualizado.'),
            __('No se pudo actualizar el cargo. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('position'));
    }

    /**
     * Elimina un cargo.
     *
     * @param string|null $id Position id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
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

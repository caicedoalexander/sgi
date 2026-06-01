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

    #[Permission(action: 'view')]
    public function index()
    {
        $positions = $this->paginate($this->Positions);

        $this->set(compact('positions'));
    }

    #[Permission(action: 'view')]
    public function view($id = null)
    {
        $position = $this->Positions->get($id);

        $this->set(compact('position'));
    }

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

    #[Permission(action: 'edit')]
    public function edit($id = null)
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

    #[Permission(action: 'delete')]
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

<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;
use App\Controller\Trait\ExcelWizardTrait;

class MaritalStatusesController extends AppController
{
    use CatalogCrudTrait;
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    #[Permission(action: 'view')]
    public function index()
    {
        $maritalStatuses = $this->paginate($this->MaritalStatuses);

        $this->set(compact('maritalStatuses'));
    }

    #[Permission(action: 'view')]
    public function view($id = null)
    {
        $maritalStatus = $this->MaritalStatuses->get($id);

        $this->set(compact('maritalStatus'));
    }

    #[Permission(action: 'add')]
    public function add()
    {
        $maritalStatus = $this->MaritalStatuses->newEmptyEntity();
        $result = $this->_catalogSave(
            $this->MaritalStatuses,
            $maritalStatus,
            __('El estado civil ha sido guardado.'),
            __('No se pudo guardar el estado civil. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('maritalStatus'));
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $maritalStatus = $this->MaritalStatuses->get($id);
        $result = $this->_catalogSave(
            $this->MaritalStatuses,
            $maritalStatus,
            __('El estado civil ha sido actualizado.'),
            __('No se pudo actualizar el estado civil. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('maritalStatus'));
    }

    #[Permission(action: 'delete')]
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $maritalStatus = $this->MaritalStatuses->get($id);
        if ($this->MaritalStatuses->delete($maritalStatus)) {
            $this->Flash->success(__('El estado civil ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el estado civil. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

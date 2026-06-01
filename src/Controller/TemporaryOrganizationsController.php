<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;
use App\Controller\Trait\ExcelWizardTrait;

class TemporaryOrganizationsController extends AppController
{
    use CatalogCrudTrait;
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    #[Permission(action: 'view')]
    public function index()
    {
        $temporaryOrganizations = $this->paginate($this->TemporaryOrganizations);

        $this->set(compact('temporaryOrganizations'));
    }

    #[Permission(action: 'add')]
    public function add()
    {
        $temporaryOrganization = $this->TemporaryOrganizations->newEmptyEntity();
        $result = $this->_catalogSave(
            $this->TemporaryOrganizations,
            $temporaryOrganization,
            __('La organización temporal ha sido guardada.'),
            __('No se pudo guardar la organización temporal. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('temporaryOrganization'));
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $temporaryOrganization = $this->TemporaryOrganizations->get($id);
        $result = $this->_catalogSave(
            $this->TemporaryOrganizations,
            $temporaryOrganization,
            __('La organización temporal ha sido actualizada.'),
            __('No se pudo actualizar la organización temporal. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('temporaryOrganization'));
    }

    #[Permission(action: 'delete')]
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $temporaryOrganization = $this->TemporaryOrganizations->get($id);
        if ($this->TemporaryOrganizations->delete($temporaryOrganization)) {
            $this->Flash->success(__('La organización temporal ha sido eliminada.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar la organización temporal. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

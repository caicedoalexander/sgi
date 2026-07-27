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

    /**
     * Lista las empresas temporales.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $temporaryOrganizations = $this->paginate($this->TemporaryOrganizations);

        $this->set(compact('temporaryOrganizations'));
    }

    /**
     * Crea una empresa temporal.
     *
     * @return \Cake\Http\Response|null
     */
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

    /**
     * Edita una empresa temporal.
     *
     * @param string|null $id ID de la empresa temporal.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
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

    /**
     * Elimina una empresa temporal.
     *
     * @param string|null $id ID de la empresa temporal.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
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

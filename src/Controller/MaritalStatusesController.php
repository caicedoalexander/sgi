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

    /**
     * Lista los estados civiles.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $maritalStatuses = $this->paginate($this->MaritalStatuses);

        $this->set(compact('maritalStatuses'));
    }

    /**
     * Muestra un estado civil.
     *
     * @param string|null $id Marital status id.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null)
    {
        $maritalStatus = $this->MaritalStatuses->get($id);

        $this->set(compact('maritalStatus'));
    }

    /**
     * Crea un estado civil.
     *
     * @return \Cake\Http\Response|null
     */
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

    /**
     * Edita un estado civil.
     *
     * @param string|null $id Marital status id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
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

    /**
     * Elimina un estado civil.
     *
     * @param string|null $id Marital status id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
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

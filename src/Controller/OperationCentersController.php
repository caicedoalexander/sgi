<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;
use App\Controller\Trait\ExcelWizardTrait;

class OperationCentersController extends AppController
{
    use CatalogCrudTrait;
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista los centros de operación.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $operationCenters = $this->paginate($this->OperationCenters);

        $this->set(compact('operationCenters'));
    }

    /**
     * Muestra un centro de operación.
     *
     * @param string|null $id Operation center id.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null)
    {
        $operationCenter = $this->OperationCenters->get($id, contain: ['Invoices']);

        $this->set(compact('operationCenter'));
    }

    /**
     * Crea un centro de operación.
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add()
    {
        $operationCenter = $this->OperationCenters->newEmptyEntity();
        $result = $this->_catalogSave(
            $this->OperationCenters,
            $operationCenter,
            __('El centro de operación ha sido guardado.'),
            __('No se pudo guardar el centro de operación. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('operationCenter'));
    }

    /**
     * Edita un centro de operación.
     *
     * @param string|null $id Operation center id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
    {
        $operationCenter = $this->OperationCenters->get($id);
        $result = $this->_catalogSave(
            $this->OperationCenters,
            $operationCenter,
            __('El centro de operación ha sido actualizado.'),
            __('No se pudo actualizar el centro de operación. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('operationCenter'));
    }

    /**
     * Elimina un centro de operación.
     *
     * @param string|null $id Operation center id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $operationCenter = $this->OperationCenters->get($id);
        if ($this->OperationCenters->delete($operationCenter)) {
            $this->Flash->success(__('El centro de operación ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el centro de operación. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

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

    #[Permission(action: 'view')]
    public function index()
    {
        $operationCenters = $this->paginate($this->OperationCenters);

        $this->set(compact('operationCenters'));
    }

    #[Permission(action: 'view')]
    public function view($id = null)
    {
        $operationCenter = $this->OperationCenters->get($id, contain: ['Invoices']);

        $this->set(compact('operationCenter'));
    }

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

    #[Permission(action: 'edit')]
    public function edit($id = null)
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

    #[Permission(action: 'delete')]
    public function delete($id = null)
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

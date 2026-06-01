<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;

class ExpenseTypesController extends AppController
{
    use CatalogCrudTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    #[Permission(action: 'view')]
    public function index()
    {
        $expenseTypes = $this->paginate($this->ExpenseTypes);

        $this->set(compact('expenseTypes'));
    }

    #[Permission(action: 'view')]
    public function view($id = null)
    {
        $expenseType = $this->ExpenseTypes->get($id, contain: ['Invoices']);

        $this->set(compact('expenseType'));
    }

    #[Permission(action: 'add')]
    public function add()
    {
        $expenseType = $this->ExpenseTypes->newEmptyEntity();
        $result = $this->_catalogSave(
            $this->ExpenseTypes,
            $expenseType,
            __('El tipo de gasto ha sido guardado.'),
            __('No se pudo guardar el tipo de gasto. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('expenseType'));
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $expenseType = $this->ExpenseTypes->get($id);
        $result = $this->_catalogSave(
            $this->ExpenseTypes,
            $expenseType,
            __('El tipo de gasto ha sido actualizado.'),
            __('No se pudo actualizar el tipo de gasto. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('expenseType'));
    }

    #[Permission(action: 'delete')]
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $expenseType = $this->ExpenseTypes->get($id);
        if ($this->ExpenseTypes->delete($expenseType)) {
            $this->Flash->success(__('El tipo de gasto ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el tipo de gasto. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

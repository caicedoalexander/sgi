<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;

class ExpenseTypesController extends AppController
{
    use CatalogCrudTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista los tipos de gasto.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $expenseTypes = $this->paginate($this->ExpenseTypes);

        $this->set(compact('expenseTypes'));
    }

    /**
     * Muestra un tipo de gasto.
     *
     * @param string|null $id Expense type id.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null)
    {
        $expenseType = $this->ExpenseTypes->get($id, contain: ['Invoices']);

        $this->set(compact('expenseType'));
    }

    /**
     * Crea un tipo de gasto.
     *
     * @return \Cake\Http\Response|null
     */
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

    /**
     * Edita un tipo de gasto.
     *
     * @param string|null $id Expense type id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
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

    /**
     * Elimina un tipo de gasto.
     *
     * @param string|null $id Expense type id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
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

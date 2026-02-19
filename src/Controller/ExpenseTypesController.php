<?php
declare(strict_types=1);

namespace App\Controller;

class ExpenseTypesController extends AppController
{
    public function index()
    {
        $expenseTypes = $this->paginate($this->ExpenseTypes);

        $this->set(compact('expenseTypes'));
    }

    public function view($id = null)
    {
        $expenseType = $this->ExpenseTypes->get($id, contain: ['Invoices']);

        $this->set(compact('expenseType'));
    }

    public function add()
    {
        $expenseType = $this->ExpenseTypes->newEmptyEntity();
        if ($this->request->is('post')) {
            $expenseType = $this->ExpenseTypes->patchEntity($expenseType, $this->request->getData());
            if ($this->ExpenseTypes->save($expenseType)) {
                $this->Flash->success(__('El tipo de gasto ha sido guardado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar el tipo de gasto. Intente de nuevo.'));
        }

        $this->set(compact('expenseType'));
    }

    public function edit($id = null)
    {
        $expenseType = $this->ExpenseTypes->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $expenseType = $this->ExpenseTypes->patchEntity($expenseType, $this->request->getData());
            if ($this->ExpenseTypes->save($expenseType)) {
                $this->Flash->success(__('El tipo de gasto ha sido actualizado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar el tipo de gasto. Intente de nuevo.'));
        }

        $this->set(compact('expenseType'));
    }

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

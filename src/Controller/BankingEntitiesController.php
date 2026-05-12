<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;

class BankingEntitiesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    #[Permission(action: 'view')]
    public function index()
    {
        $bankingEntities = $this->paginate($this->BankingEntities);

        $this->set(compact('bankingEntities'));
    }

    #[Permission(action: 'add')]
    public function add()
    {
        $bankingEntity = $this->BankingEntities->newEmptyEntity();
        if ($this->request->is('post')) {
            $bankingEntity = $this->BankingEntities->patchEntity($bankingEntity, $this->request->getData());
            if ($this->BankingEntities->save($bankingEntity)) {
                $this->Flash->success(__('La entidad bancaria ha sido guardada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar la entidad bancaria. Intente de nuevo.'));
        }

        $this->set(compact('bankingEntity'));
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $bankingEntity = $this->BankingEntities->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $bankingEntity = $this->BankingEntities->patchEntity($bankingEntity, $this->request->getData());
            if ($this->BankingEntities->save($bankingEntity)) {
                $this->Flash->success(__('La entidad bancaria ha sido actualizada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar la entidad bancaria. Intente de nuevo.'));
        }

        $this->set(compact('bankingEntity'));
    }

    #[Permission(action: 'delete')]
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $bankingEntity = $this->BankingEntities->get($id);
        if ($this->BankingEntities->delete($bankingEntity)) {
            $this->Flash->success(__('La entidad bancaria ha sido eliminada.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar la entidad bancaria. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

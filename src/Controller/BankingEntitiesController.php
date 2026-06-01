<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;

class BankingEntitiesController extends AppController
{
    use CatalogCrudTrait;

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
        $result = $this->_catalogSave(
            $this->BankingEntities,
            $bankingEntity,
            __('La entidad bancaria ha sido guardada.'),
            __('No se pudo guardar la entidad bancaria. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('bankingEntity'));
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $bankingEntity = $this->BankingEntities->get($id);
        $result = $this->_catalogSave(
            $this->BankingEntities,
            $bankingEntity,
            __('La entidad bancaria ha sido actualizada.'),
            __('No se pudo actualizar la entidad bancaria. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
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

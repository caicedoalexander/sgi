<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;

class ApproversController extends AppController
{
    use CatalogCrudTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista los aprobadores.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $query = $this->Approvers->find()->contain(['Users', 'OperationCenters']);
        $approvers = $this->paginate($query);

        $this->set(compact('approvers'));
    }

    /**
     * Crea un aprobador.
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add()
    {
        $approver = $this->Approvers->newEmptyEntity();
        $approver->active = true;
        $result = $this->_catalogSave(
            $this->Approvers,
            $approver,
            'El aprobador ha sido guardado.',
            'No se pudo guardar el aprobador.',
        );
        if ($result !== null) {
            return $result;
        }

        $users = $this->Approvers->Users->find('list', limit: 200)->all();
        $operationCenters = $this->Approvers->OperationCenters->find('codeList')->all();

        $this->set(compact('approver', 'users', 'operationCenters'));
    }

    /**
     * Edita un aprobador.
     *
     * @param string|null $id ID del aprobador.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
    {
        $approver = $this->Approvers->get($id, contain: ['Users', 'OperationCenters']);
        $result = $this->_catalogSave(
            $this->Approvers,
            $approver,
            'El aprobador ha sido actualizado.',
            'No se pudo actualizar el aprobador.',
        );
        if ($result !== null) {
            return $result;
        }

        $users = $this->Approvers->Users->find('list', limit: 200)->all();
        $operationCenters = $this->Approvers->OperationCenters->find('codeList')->all();

        $this->set(compact('approver', 'users', 'operationCenters'));
    }

    /**
     * Elimina un aprobador.
     *
     * @param string|null $id ID del aprobador.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $approver = $this->Approvers->get($id);
        if ($this->Approvers->delete($approver)) {
            $this->Flash->success('El aprobador ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el aprobador.');
        }

        return $this->redirect(['action' => 'index']);
    }
}

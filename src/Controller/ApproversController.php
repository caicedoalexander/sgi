<?php
declare(strict_types=1);

namespace App\Controller;

class ApproversController extends AppController
{
    public function index()
    {
        $query = $this->Approvers->find()->contain(['Users', 'OperationCenters']);
        $approvers = $this->paginate($query);

        $this->set(compact('approvers'));
    }

    public function add()
    {
        $approver = $this->Approvers->newEmptyEntity();
        if ($this->request->is('post')) {
            $approver = $this->Approvers->patchEntity($approver, $this->request->getData());
            if ($this->Approvers->save($approver)) {
                $this->Flash->success('El aprobador ha sido guardado.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('No se pudo guardar el aprobador.');
        }

        $users = $this->Approvers->Users->find('list', limit: 200)->all();
        $operationCenters = $this->Approvers->OperationCenters->find('codeList')->all();

        $this->set(compact('approver', 'users', 'operationCenters'));
    }

    public function edit($id = null)
    {
        $approver = $this->Approvers->get($id, contain: ['Users', 'OperationCenters']);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $approver = $this->Approvers->patchEntity($approver, $this->request->getData());
            if ($this->Approvers->save($approver)) {
                $this->Flash->success('El aprobador ha sido actualizado.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('No se pudo actualizar el aprobador.');
        }

        $users = $this->Approvers->Users->find('list', limit: 200)->all();
        $operationCenters = $this->Approvers->OperationCenters->find('codeList')->all();

        $this->set(compact('approver', 'users', 'operationCenters'));
    }

    public function delete($id = null)
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

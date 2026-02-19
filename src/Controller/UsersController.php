<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthorizationService;

class UsersController extends AppController
{
    public function beforeFilter(\Cake\Event\EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['login']);
    }

    public function login()
    {
        $this->viewBuilder()->setLayout('login');
        $this->request->allowMethod(['get', 'post']);

        $result = $this->Authentication->getResult();
        if ($result && $result->isValid()) {
            $redirect = $this->request->getQuery('redirect', ['controller' => 'Invoices', 'action' => 'index']);
            return $this->redirect($redirect);
        }

        if ($this->request->is('post') && !$result->isValid()) {
            $this->Flash->error('Usuario o contraseña incorrectos.');
        }
    }

    public function logout()
    {
        $result = $this->Authentication->getResult();
        if ($result && $result->isValid()) {
            $this->Authentication->logout();
        }
        return $this->redirect(['action' => 'login']);
    }

    public function index()
    {
        $query = $this->Users->find()->contain(['Roles']);
        $users = $this->paginate($query);

        $this->set(compact('users'));
    }

    public function view($id = null)
    {
        $user = $this->Users->get($id, contain: ['Roles']);

        $this->set(compact('user'));
    }

    public function add()
    {
        $user = $this->Users->newEmptyEntity();
        if ($this->request->is('post')) {
            $user = $this->Users->patchEntity($user, $this->request->getData());
            if ($this->Users->save($user)) {
                $this->Flash->success(__('El usuario ha sido guardado.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar el usuario. Intente de nuevo.'));
        }
        $roles = $this->Users->Roles->find('list', limit: 200)->all();

        $this->set(compact('user', 'roles'));
    }

    public function edit($id = null)
    {
        $user = $this->Users->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();
            if (empty($data['password'])) {
                unset($data['password']);
            }
            $user = $this->Users->patchEntity($user, $data);
            if ($this->Users->save($user)) {
                $this->Flash->success(__('El usuario ha sido actualizado.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar el usuario. Intente de nuevo.'));
        }
        $roles = $this->Users->Roles->find('list', limit: 200)->all();

        $this->set(compact('user', 'roles'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $user = $this->Users->get($id);
        if ($this->Users->delete($user)) {
            $this->Flash->success(__('El usuario ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el usuario. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

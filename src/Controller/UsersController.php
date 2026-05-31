<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\NoAuthGate;
use App\Attribute\Permission;
use Cake\Event\EventInterface;
use Cake\ORM\TableRegistry;

class UsersController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['login']);
    }

    #[NoAuthGate(reason: 'External flow before authentication')]
    public function login()
    {
        $this->viewBuilder()->setLayout('login');
        $this->request->allowMethod(['get', 'post']);

        $result = $this->Authentication->getResult();

        // B-7: lockout por email (defensa frente a credential stuffing distribuido,
        // donde el rate limit por IP del middleware no aplica). Ventana de 15 min.
        $email = strtolower(trim((string)$this->request->getData('email', '')));
        $buckets = TableRegistry::getTableLocator()->get('RateLimitBuckets');
        $window = 900;
        $windowStart = (int)floor(time() / $window) * $window;
        $emailKey = hash('sha256', 'login_email|' . $email . '|' . $windowStart);
        $maxFailures = 10;

        if ($email !== '' && $buckets->getCount($emailKey) >= $maxFailures) {
            $this->Flash->error('Demasiados intentos fallidos para esta cuenta. Intentá de nuevo en unos minutos.');

            return null;
        }

        if ($result && $result->isValid()) {
            return $this->redirect($this->_safeLoginRedirect());
        }

        if ($this->request->is('post') && !$result->isValid()) {
            if ($email !== '') {
                $buckets->incrementAndGet($emailKey, $windowStart);
            }
            $this->Flash->error('Usuario o contraseña incorrectos.');
        }
    }

    /**
     * B-5: devuelve un destino de redirección post-login seguro. Solo acepta
     * rutas internas relativas (descarta URLs absolutas y protocol-relative
     * que habilitarían open redirect / phishing).
     *
     * @return array|string
     */
    private function _safeLoginRedirect(): array|string
    {
        $redirect = $this->request->getQuery('redirect');

        if (
            is_string($redirect)
            && $redirect !== ''
            && str_starts_with($redirect, '/')
            && !str_starts_with($redirect, '//')
        ) {
            return $redirect;
        }

        return ['controller' => 'Dashboard', 'action' => 'index'];
    }

    #[NoAuthGate(reason: 'Always available to authenticated users')]
    public function logout()
    {
        $result = $this->Authentication->getResult();
        if ($result && $result->isValid()) {
            $this->Authentication->logout();
        }

        return $this->redirect(['action' => 'login']);
    }

    #[Permission(action: 'view')]
    public function index(): void
    {
        $query = $this->Users->find()->contain(['Roles']);
        $users = $this->paginate($query);

        $this->set(compact('users'));
    }

    #[Permission(action: 'view')]
    public function view($id = null): void
    {
        $user = $this->Users->get($id, contain: ['Roles']);

        $this->set(compact('user'));
    }

    #[Permission(action: 'add')]
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

    #[Permission(action: 'edit')]
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

    #[Permission(action: 'delete')]
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

<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\NoAuthGate;
use App\Attribute\Permission;
use App\Service\LoginThrottleService;
use Cake\Event\EventInterface;

class UsersController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Mensaje único para la cuenta bloqueada: idéntico para éxito y fallo
     * durante el bloqueo, de modo que la respuesta no revele si las
     * credenciales eran correctas (oráculo cerrado).
     */
    private const BLOCKED_MESSAGE = 'Demasiados intentos fallidos para esta cuenta. Intentá de nuevo en unos minutos.';

    /**
     * Configura el acceso previo a las acciones.
     *
     * @param \Cake\Event\EventInterface $event Evento del ciclo de vida.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['login']);
    }

    /**
     * Autentica a un usuario en el sistema.
     *
     * @return \Cake\Http\Response|null
     */
    #[NoAuthGate(reason: 'External flow before authentication')]
    public function login()
    {
        $this->viewBuilder()->setLayout('login');
        $this->request->allowMethod(['get', 'post']);

        $result = $this->Authentication->getResult();
        /** @var \App\Service\LoginThrottleService $throttle */
        $throttle = $this->getContainer()->get(LoginThrottleService::class);

        if ($this->request->is('post')) {
            $username = trim((string)$this->request->getData('username'));

            // Enforcement: cuenta bloqueada → denegar todo intento (éxito o fallo)
            // con el mismo mensaje. Cierra el oráculo éxito/fallo.
            if ($username !== '' && $throttle->isBlocked($username)) {
                if ($result && $result->isValid()) {
                    $this->Authentication->logout();
                }
                $this->Flash->error(self::BLOCKED_MESSAGE);

                return null;
            }

            if ($result && $result->isValid()) {
                if ($username !== '') {
                    $throttle->clear($username);
                }

                return $this->redirect($this->_safeLoginRedirect());
            }

            if ($username !== '') {
                $throttle->registerFailure($username);
            }

            if ($username !== '' && $throttle->isBlocked($username)) {
                $this->Flash->error(self::BLOCKED_MESSAGE);
            } else {
                $this->Flash->error('Usuario o contraseña incorrectos.');
            }

            return null;
        }

        if ($result && $result->isValid()) {
            return $this->redirect($this->_safeLoginRedirect());
        }

        return null;
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

    /**
     * Cierra la sesión del usuario.
     *
     * @return \Cake\Http\Response|null
     */
    #[NoAuthGate(reason: 'Always available to authenticated users')]
    public function logout()
    {
        $result = $this->Authentication->getResult();
        if ($result && $result->isValid()) {
            $this->Authentication->logout();
        }

        return $this->redirect(['action' => 'login']);
    }

    /**
     * Lista los usuarios.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index(): void
    {
        $query = $this->Users->find()->contain(['Roles']);
        $users = $this->paginate($query);

        $this->set(compact('users'));
    }

    /**
     * Muestra un usuario.
     *
     * @param string|null $id ID del usuario.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null): void
    {
        $user = $this->Users->get($id, contain: ['Roles']);

        $this->set(compact('user'));
    }

    /**
     * Crea un usuario.
     *
     * @return \Cake\Http\Response|null
     */
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

    /**
     * Edita un usuario.
     *
     * @param string|null $id ID del usuario.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
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

    /**
     * Elimina un usuario.
     *
     * @param string|null $id ID del usuario.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
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

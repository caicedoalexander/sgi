<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;
use App\Controller\Trait\ExcelWizardTrait;

class ProvidersController extends AppController
{
    use CatalogCrudTrait;
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    #[Permission(action: 'view')]
    public function index()
    {
        $providers = $this->paginate($this->Providers);

        $this->set(compact('providers'));
    }

    #[Permission(action: 'view')]
    public function view($id = null)
    {
        $provider = $this->Providers->get($id, contain: ['Invoices']);

        $this->set(compact('provider'));
    }

    #[Permission(action: 'add')]
    public function add()
    {
        $provider = $this->Providers->newEmptyEntity();
        $result = $this->_catalogSave(
            $this->Providers,
            $provider,
            __('El proveedor ha sido guardado.'),
            __('No se pudo guardar el proveedor. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('provider'));
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $provider = $this->Providers->get($id);
        $result = $this->_catalogSave(
            $this->Providers,
            $provider,
            __('El proveedor ha sido actualizado.'),
            __('No se pudo actualizar el proveedor. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('provider'));
    }

    #[Permission(action: 'delete')]
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $provider = $this->Providers->get($id);
        if ($this->Providers->delete($provider)) {
            $this->Flash->success(__('El proveedor ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el proveedor. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

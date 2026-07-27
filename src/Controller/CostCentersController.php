<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;
use App\Controller\Trait\ExcelWizardTrait;

class CostCentersController extends AppController
{
    use CatalogCrudTrait;
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista los centros de costos.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $costCenters = $this->paginate($this->CostCenters);

        $this->set(compact('costCenters'));
    }

    /**
     * Muestra un centro de costos.
     *
     * @param string|null $id Cost center id.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null)
    {
        $costCenter = $this->CostCenters->get($id, contain: ['Invoices']);

        $this->set(compact('costCenter'));
    }

    /**
     * Crea un centro de costos.
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add()
    {
        $costCenter = $this->CostCenters->newEmptyEntity();
        $result = $this->_catalogSave(
            $this->CostCenters,
            $costCenter,
            __('El centro de costos ha sido guardado.'),
            __('No se pudo guardar el centro de costos. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('costCenter'));
    }

    /**
     * Edita un centro de costos.
     *
     * @param string|null $id Cost center id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
    {
        $costCenter = $this->CostCenters->get($id);
        $result = $this->_catalogSave(
            $this->CostCenters,
            $costCenter,
            __('El centro de costos ha sido actualizado.'),
            __('No se pudo actualizar el centro de costos. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('costCenter'));
    }

    /**
     * Elimina un centro de costos.
     *
     * @param string|null $id Cost center id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $costCenter = $this->CostCenters->get($id);
        if ($this->CostCenters->delete($costCenter)) {
            $this->Flash->success(__('El centro de costos ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el centro de costos. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

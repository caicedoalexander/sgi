<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Service\ConsumableStockService;
use App\Service\ServiceResult;
use Cake\Http\Response;

class ConsumablesController extends AppController
{
    /**
     * @var array<string, int>
     */
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista de consumibles con filtro opcional de stock bajo.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index(): void
    {
        $lowStockOnly = $this->request->getQuery('low_stock') === '1';
        $query = $lowStockOnly ? $this->Consumables->find('lowStock') : $this->Consumables->find();
        $query->contain(['OperationCenters'])->orderBy(['Consumables.description' => 'ASC']);

        $consumables = $this->paginate($query);

        $this->set(compact('consumables', 'lowStockOnly'));
    }

    /**
     * Detalle de un consumible con historial de movimientos.
     *
     * @param string|null $id ID del consumible.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null): void
    {
        $consumable = $this->Consumables->get($id, contain: ['OperationCenters']);
        $movements = $this->fetchTable('ConsumableMovements')->find('forConsumable', consumableId: (int)$consumable->id)
            ->contain(['PerformedByUsers'])->all()->toArray();
        $canEdit = $this->_checkPermission('consumables', 'edit');

        $this->set(compact('consumable', 'movements', 'canEdit'));
    }

    /**
     * Crea un nuevo consumible.
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add(): ?Response
    {
        $consumable = $this->Consumables->newEmptyEntity();
        if ($this->request->is('post')) {
            $consumable = $this->Consumables->patchEntity($consumable, $this->request->getData());
            if ($this->Consumables->save($consumable)) {
                $this->Flash->success(__('El consumible ha sido creado.'));

                return $this->redirect(['action' => 'view', $consumable->id]);
            }
            $this->Flash->error(__('No se pudo crear el consumible. Revise los datos.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('consumable'));

        return null;
    }

    /**
     * Edita un consumible existente.
     *
     * @param string|null $id ID del consumible.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null): ?Response
    {
        $consumable = $this->Consumables->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $consumable = $this->Consumables->patchEntity($consumable, $this->request->getData());
            if ($this->Consumables->save($consumable)) {
                $this->Flash->success(__('El consumible ha sido actualizado.'));

                return $this->redirect(['action' => 'view', $consumable->id]);
            }
            $this->Flash->error(__('No se pudo actualizar el consumible. Revise los datos.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('consumable'));

        return null;
    }

    /**
     * Elimina un consumible.
     *
     * @param string|null $id ID del consumible.
     * @return \Cake\Http\Response
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null): Response
    {
        $this->request->allowMethod(['post', 'delete']);
        $consumable = $this->Consumables->get($id);
        if ($this->Consumables->delete($consumable)) {
            $this->Flash->success(__('El consumible ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el consumible. Si tiene movimientos, no puede borrarse.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Registra una entrada de stock para el consumible.
     *
     * @param string|null $id ID del consumible.
     * @return \Cake\Http\Response
     */
    #[Permission(action: 'edit')]
    public function stockIn(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $result = (new ConsumableStockService())->registerIngress(
            (int)$id,
            (int)$this->request->getData('quantity'),
            ['reason' => $this->request->getData('reason')],
            $this->_currentUserId(),
        );
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Registra una salida de stock para el consumible.
     *
     * @param string|null $id ID del consumible.
     * @return \Cake\Http\Response
     */
    #[Permission(action: 'edit')]
    public function stockOut(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $result = (new ConsumableStockService())->registerOutput(
            (int)$id,
            (int)$this->request->getData('quantity'),
            ['reason' => $this->request->getData('reason')],
            $this->_currentUserId(),
        );
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Setea los dropdowns compartidos por add y edit.
     *
     * @return void
     */
    protected function _setFormDropdowns(): void
    {
        $operationCenters = $this->fetchTable('OperationCenters')->find('list')->all()->toArray();
        $this->set(compact('operationCenters'));
    }

    /**
     * Retorna el ID del usuario autenticado actualmente.
     *
     * @return int
     */
    protected function _currentUserId(): int
    {
        return (int)$this->Authentication->getIdentity()->getIdentifier();
    }

    /**
     * Muestra un flash de éxito o error según el ServiceResult recibido.
     *
     * @param \App\Service\ServiceResult $result Resultado de la operación de servicio.
     * @return void
     */
    protected function _flashResult(ServiceResult $result): void
    {
        if ($result->success) {
            $message = is_array($result->data)
            ? ($result->data['message'] ?? 'Operación realizada.')
            : 'Operación realizada.';
            $this->Flash->success($message);

            return;
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo completar la operación.');
    }
}

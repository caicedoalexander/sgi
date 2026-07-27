<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Constants\AssetConstants;
use App\Service\AssetDocumentService;
use App\Service\AssetInventoryService;
use App\Service\ServiceResult;
use App\ViewModel\AssetViewViewModel;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;

class AssetsController extends AppController
{
    /**
     * @var array<string, int>
     */
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista de activos con filtros y paginación.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index(): void
    {
        $filters = [
            'status' => $this->request->getQuery('status'),
            'category_id' => $this->request->getQuery('category_id'),
            'operation_center_id' => $this->request->getQuery('operation_center_id'),
            'q' => $this->request->getQuery('q'),
        ];

        $query = $this->Assets->find('filtered', options: $filters)
            ->contain(['AssetCategories', 'ResponsibleEmployees', 'OperationCenters'])
            ->orderBy(['Assets.created' => 'DESC']);

        $assets = $this->paginate($query);
        $categories = $this->Assets->AssetCategories->find('active')->all()->combine('id', 'name')->toArray();
        $operationCenters = $this->fetchTable('OperationCenters')->find('list')->all()->toArray();
        $statusLabels = AssetConstants::STATUS_LABELS;

        $this->set(compact('assets', 'categories', 'operationCenters', 'statusLabels', 'filters'));
    }

    /**
     * Detalle de un activo: información, historial de movimientos y documentos.
     *
     * @param string|null $id ID del activo.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null): void
    {
        $asset = $this->Assets->get($id, contain: [
            'AssetCategories', 'ResponsibleEmployees', 'OperationCenters', 'CostCenters',
        ]);

        $movements = $this->fetchTable('AssetMovements')->find('forAsset', assetId: (int)$asset->id)
            ->contain(['FromEmployees', 'ToEmployees', 'FromOperationCenters', 'ToOperationCenters',
                'PerformedByUsers'])
            ->all()->toArray();
        $documents = $this->fetchTable('AssetDocuments')->find('forAsset', assetId: (int)$asset->id)->all()->toArray();

        // Correction 1: no `active` column on employees; use full_name virtual field.
        $employees = $this->fetchTable('Employees')->find()
            ->orderBy(['Employees.first_name' => 'ASC'])
            ->all()
            ->combine('id', fn($e) => $e->full_name)
            ->toArray();
        $operationCenters = $this->fetchTable('OperationCenters')->find('list')->all()->toArray();

        $viewModel = new AssetViewViewModel(
            asset: $asset,
            movements: $movements,
            documents: $documents,
            canEdit: $this->_checkPermission('assets', 'edit'),
            canDelete: $this->_checkPermission('assets', 'delete'),
            canCreateMovement: $this->_checkPermission('assets', 'edit'),
            employees: $employees,
            operationCenters: $operationCenters,
        );

        $this->set(compact('viewModel'));
    }

    /**
     * Crea un nuevo activo.
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add(): ?Response
    {
        $asset = $this->Assets->newEmptyEntity();
        if ($this->request->is('post')) {
            $asset = $this->Assets->patchEntity($asset, $this->request->getData());
            $asset->status = AssetConstants::STATUS_DISPONIBLE;
            if ($this->Assets->save($asset)) {
                $this->Flash->success(__('El activo ha sido creado.'));

                return $this->redirect(['action' => 'view', $asset->id]);
            }
            $this->Flash->error(__('No se pudo crear el activo. Revise los datos.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('asset'));

        return null;
    }

    /**
     * Edita un activo existente.
     *
     * @param string|null $id ID del activo.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null): ?Response
    {
        $asset = $this->Assets->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $asset = $this->Assets->patchEntity($asset, $this->request->getData());
            if ($this->Assets->save($asset)) {
                $this->Flash->success(__('El activo ha sido actualizado.'));

                return $this->redirect(['action' => 'view', $asset->id]);
            }
            $this->Flash->error(__('No se pudo actualizar el activo. Revise los datos.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('asset'));

        return null;
    }

    /**
     * Elimina un activo.
     *
     * @param string|null $id ID del activo.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post', 'delete']);
        $asset = $this->Assets->get($id);
        if ($this->Assets->delete($asset)) {
            $this->Flash->success(__('El activo ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el activo. Si tiene movimientos registrados, ' .
                'dé de baja en su lugar.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Asigna un activo disponible a un empleado.
     *
     * @param string|null $id ID del activo.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function assign(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->assign(
            (int)$id,
            (int)$this->request->getData('to_employee_id'),
            $this->_movementData(),
            $this->_currentUserId(),
        );
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Presta un activo disponible a un empleado.
     *
     * @param string|null $id ID del activo.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function lend(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->lend(
            (int)$id,
            (int)$this->request->getData('to_employee_id'),
            $this->_movementData(),
            $this->_currentUserId(),
        );
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Devuelve un activo asignado o prestado a disponible.
     *
     * @param string|null $id ID del activo.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function returnAsset(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->returnAsset((int)$id, $this->_movementData(), $this->_currentUserId());
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Traslada un activo a otro centro de operación.
     *
     * @param string|null $id ID del activo.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function transfer(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->transfer(
            (int)$id,
            (int)$this->request->getData('to_operation_center_id'),
            $this->_movementData(),
            $this->_currentUserId(),
        );
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Da de baja un activo (estado terminal).
     *
     * @param string|null $id ID del activo.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function dispose(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->dispose((int)$id, $this->_movementData(), $this->_currentUserId());
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Registra un movimiento de ingreso sobre el activo.
     *
     * @param string|null $id ID del activo.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function registerIngress(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $result = (new AssetInventoryService())->registerIngress(
            (int)$id,
            $this->_movementData(),
            $this->_currentUserId(),
        );
        $this->_flashResult($result);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Sube un documento (acta, soporte, foto, etc.) a un activo.
     *
     * @param string|null $id ID del activo.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function uploadDocument(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $file = $this->request->getUploadedFile('document');
        if ($file === null) {
            $this->Flash->error('Seleccione un archivo.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $movementId = $this->request->getData('asset_movement_id');
        $result = (new AssetDocumentService())->uploadDocument(
            (int)$id,
            $file,
            (string)$this->request->getData('document_type'),
            $movementId !== null && $movementId !== '' ? (int)$movementId : null,
            $this->_currentUserId(),
        );

        if ($result->success) {
            $this->Flash->success('Documento subido correctamente.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo subir el documento.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Elimina un documento de activo y su archivo físico.
     *
     * @param string|null $id ID del documento.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function deleteDocument(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $document = $this->fetchTable('AssetDocuments')->get($id);
        $result = (new AssetDocumentService())->deleteDocument((int)$document->asset_id, (int)$id);

        if ($result->success) {
            $this->Flash->success('Documento eliminado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo eliminar el documento.');
        }

        return $this->redirect(['action' => 'view', $document->asset_id]);
    }

    /**
     * Descarga un documento de activo desde el almacenamiento privado.
     *
     * @param string|null $id ID del documento.
     * @return \Cake\Http\Response
     */
    #[Permission(action: 'view')]
    public function downloadDocument(?string $id = null): Response
    {
        $this->request->allowMethod(['get']);
        $document = $this->fetchTable('AssetDocuments')->get($id);
        $absolutePath = (new AssetDocumentService())->resolveStoragePath($document->file_path);

        if (!is_file($absolutePath)) {
            throw new NotFoundException('El archivo no existe.');
        }

        return $this->response->withFile($absolutePath, ['download' => true, 'name' => basename($document->name)]);
    }

    /**
     * Metadatos comunes del movimiento desde el POST.
     *
     * @return array<string, mixed>
     */
    protected function _movementData(): array
    {
        return [
            'reason' => $this->request->getData('reason'),
            'movement_date' => $this->request->getData('movement_date') ?: null,
        ];
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

    /**
     * Setea los dropdowns compartidos por add y edit.
     *
     * @return void
     */
    protected function _setFormDropdowns(): void
    {
        $categories = $this->Assets->AssetCategories->find('active')->all()->combine('id', 'name')->toArray();
        $operationCenters = $this->fetchTable('OperationCenters')->find('list')->all()->toArray();
        $costCenters = $this->fetchTable('CostCenters')->find('list')->all()->toArray();

        $this->set(compact('categories', 'operationCenters', 'costCenters'));
    }
}

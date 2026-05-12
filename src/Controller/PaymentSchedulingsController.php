<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Attribute\PipelineAction;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PipelineStepConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Controller\Trait\ObservationControllerTrait;
use App\Model\Entity\PaymentScheduling;
use App\Service\PaymentSchedulingDocumentService;
use App\Service\PaymentSchedulingImportService;
use App\Service\PaymentSchedulingService;
use App\ViewModel\PaymentSchedulingAddViewModel;
use App\ViewModel\PaymentSchedulingEditViewModel;
use Cake\Routing\Router;

class PaymentSchedulingsController extends AppController
{
    use DocumentJsonPayloadTrait;
    use ObservationControllerTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private PaymentSchedulingService $schedulingService;

    private PaymentSchedulingDocumentService $documentService;

    private PaymentSchedulingImportService $importService;

    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->schedulingService = $container->get(PaymentSchedulingService::class);
        $this->documentService = $container->get(PaymentSchedulingDocumentService::class);
        $this->importService = $container->get(PaymentSchedulingImportService::class);
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    private function _getRoleName(): string
    {
        return $this->_getUserRoleName($this->_getCurrentUser());
    }

    #[Permission(action: 'view')]
    public function index()
    {
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $visibleStatuses = $this->schedulingService->getVisibleStatuses($roleId);

        $query = $this->PaymentSchedulings->find()
            ->contain(['CreatedByUsers', 'PaymentSchedulingItems'])
            ->orderBy(['PaymentSchedulings.created' => 'DESC']);

        $query->where($this->_visibleStatusConditions('PaymentSchedulings.pipeline_status', $visibleStatuses));

        // Filters
        $params = $this->request->getQueryParams();
        if (!empty($params['code'])) {
            $query->where(['PaymentSchedulings.code LIKE' => '%' . $params['code'] . '%']);
        }
        if (!empty($params['status'])) {
            $query->where(['PaymentSchedulings.pipeline_status' => $params['status']]);
        }

        $records = $this->paginate($query);
        $this->set(compact('records', 'roleName'));
    }

    #[Permission(action: 'view')]
    public function view($id = null)
    {
        $record = $this->PaymentSchedulings->get($id, contain: [
            'CreatedByUsers',
            'PaymentSchedulingItems' => [
                'Invoices' => ['Providers'],
                'BankingEntities',
            ],
            'PaymentSchedulingDocuments' => [
                'UploadedByUsers',
                'sort' => ['PaymentSchedulingDocuments.created' => 'DESC'],
            ],
            'PaymentSchedulingObservations' => [
                'Users',
                'sort' => ['PaymentSchedulingObservations.created' => 'ASC'],
            ],
        ]);

        $roleName = $this->_getRoleName();
        $total = $this->schedulingService->calculateTotal($record->id);
        $pipelineLabels = PaymentSchedulingConstants::STATUS_LABELS;

        $this->set(compact('record', 'roleName', 'total', 'pipelineLabels'));
    }

    #[Permission(action: 'add')]
    public function add()
    {
        $record = $this->PaymentSchedulings->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();
            $data['operation_center_id'] = $data['operation_center_id'] ?? null;
            $data['pipeline_status'] = PaymentSchedulingConstants::STATUS_BORRADOR;
            $data['created_by'] = $user->id;

            $record = $this->PaymentSchedulings->patchEntity($record, $data);
            if ($this->PaymentSchedulings->save($record)) {
                $this->Flash->success('Programación creada correctamente.');

                return $this->redirect(['action' => 'edit', $record->id]);
            }
            $this->Flash->error('No se pudo crear la programación.');
        }

        $vm = $this->_buildAddViewModel($record);
        $this->set('viewModel', $vm);
    }

    private function _buildAddViewModel(PaymentScheduling $record): PaymentSchedulingAddViewModel
    {
        $operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();

        return new PaymentSchedulingAddViewModel(
            record: $record,
            operationCenters: $operationCenters,
        );
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $record = $this->PaymentSchedulings->get($id, contain: [
            'CreatedByUsers',
            'PaymentSchedulingItems' => [
                'Invoices' => ['Providers'],
                'BankingEntities',
            ],
            'PaymentSchedulingDocuments' => [
                'UploadedByUsers',
                'sort' => ['PaymentSchedulingDocuments.created' => 'DESC'],
            ],
            'PaymentSchedulingObservations' => [
                'Users',
                'sort' => ['PaymentSchedulingObservations.created' => 'ASC'],
            ],
        ]);

        $roleName = $this->_getRoleName();
        $roleId = (int)$this->_getCurrentUser()->role_id;

        $vm = $this->_buildEditViewModel($record, $roleId, $roleName);
        $this->set('viewModel', $vm);
    }

    private function _buildEditViewModel(
        PaymentScheduling $record,
        int $roleId,
        string $roleName,
    ): PaymentSchedulingEditViewModel {
        $currentStatus = $record->pipeline_status;
        $userContext = $this->_userContext();
        $canAdvance = $this->schedulingService->denialReasonForAdvance($record, $roleId) === null;
        $canReject = $currentStatus === PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO
            && $this->authFacade->canOperate(
                $userContext,
                PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
                $currentStatus,
            );
        $canRegress = $this->schedulingService->denialReasonForRegress($record, $roleId) === null;
        $canConfirmPayment = $this->authFacade->canOperate(
            $userContext,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO,
        );

        $advanceErrors = $canAdvance
            ? $this->schedulingService->validateTransitionRequirements($record, $currentStatus)
            : [];

        return new PaymentSchedulingEditViewModel(
            record: $record,
            roleName: $roleName,
            currentStatus: $currentStatus,
            canAdvance: $canAdvance,
            canReject: $canReject,
            canRegress: $canRegress,
            canConfirmPayment: $canConfirmPayment,
            nextStatus: $this->schedulingService->getNextStatus($currentStatus),
            previousStatus: $this->schedulingService->getPreviousStatus($currentStatus),
            regressLockMessage: $this->schedulingService->getRegressionLockMessage($record),
            advanceErrors: $advanceErrors,
            total: $this->schedulingService->calculateTotal($record->id),
            pipelineLabels: PaymentSchedulingConstants::STATUS_LABELS,
            bankingEntities: $this->fetchTable('BankingEntities')->find('list')->all(),
        );
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS)]
    public function advance($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        if (!$this->_ensureExpectedStatus($record->pipeline_status)) {
            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();

        $advanceDenial = $this->schedulingService->denialReasonForAdvance($record, (int)$user->role_id);
        if ($advanceDenial !== null) {
            $this->Flash->error($advanceDenial->message());

            return $this->redirect(['action' => 'edit', $id]);
        }

        $errors = $this->schedulingService->validateTransitionRequirements($record, $record->pipeline_status);
        if (!empty($errors)) {
            foreach ($errors as $err) {
                $this->Flash->error($err);
            }

            return $this->redirect(['action' => 'edit', $id]);
        }

        $nextStatus = $this->schedulingService->getNextStatus($record->pipeline_status);

        // Si avanza a verificacion_pago (desde aut_pago — Contador autoriza),
        // crear los pagos y dejar las facturas hijas en verificacion_pago.
        if ($nextStatus === PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO) {
            $result = $this->schedulingService->applyPayments($record->id, (int)$user->id);
            if (!$result['success']) {
                foreach ($result['errors'] as $err) {
                    $this->Flash->error($err);
                }

                return $this->redirect(['action' => 'edit', $id]);
            }

            $advancedCount = count($result['advanced_to_pagada']);
            $partialCount = count($result['partial_payment']);
            if ($advancedCount > 0) {
                $this->Flash->success("{$advancedCount} factura(s) en Verificación de pago.");
            }
            if ($partialCount > 0) {
                $this->Flash->warning("{$partialCount} factura(s) con Pago Parcial, permanecen en Tesorería.");
            }
        }

        $record->pipeline_status = $nextStatus;
        if ($this->PaymentSchedulings->save($record)) {
            $label = PaymentSchedulingConstants::STATUS_LABELS[$nextStatus] ?? $nextStatus;
            $this->Flash->success("Programación avanzada a: {$label}");

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error('No se pudo avanzar la programación.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS)]
    public function reject($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);
        $roleId = (int)$this->_getCurrentUser()->role_id;

        $canReject = $record->pipeline_status === PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO
            && $this->authFacade->canOperate(
                $this->_userContext(),
                PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
                $record->pipeline_status,
            );
        if (!$canReject) {
            $this->Flash->error('No tiene permisos para rechazar esta programación.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $record->pipeline_status = PaymentSchedulingConstants::REJECTION_TARGET;
        if ($this->PaymentSchedulings->save($record)) {
            $this->Flash->warning('Programación devuelta a Tesorería para corrección.');
        } else {
            $this->Flash->error('No se pudo rechazar la programación.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS)]
    public function regressStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);
        $user = $this->_getCurrentUser();
        $reason = trim((string)$this->request->getData('reason', ''));

        $result = $this->schedulingService->regress(
            $record,
            (int)$user->role_id,
            (int)$user->id,
            $reason,
        );

        if ($result->success) {
            $previousStatus = $result->data['previousStatus'] ?? null;
            $prevLabel = PaymentSchedulingConstants::STATUS_LABELS[$previousStatus] ?? $previousStatus;
            $this->Flash->success(sprintf('Programación regresada a: %s', $prevLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo regresar la programación.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * Tesorería confirma que los pagos de la programación ya se ejecutaron.
     * Avanza scheduling y facturas hijas de verificacion_pago → pagada.
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS, step: PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO)]
    public function confirmPayment($id = null)
    {
        $this->request->allowMethod(['post']);

        if (
            !$this->authFacade->canOperate(
                $this->_userContext(),
                PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
                PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para confirmar este pago.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $result = $this->schedulingService->confirmPayment(
            (int)$id,
            (int)$this->_getCurrentUser()->id,
        );

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Programación confirmada.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo confirmar la programación.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    #[Permission(action: 'add')]
    public function importExcel($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        if (!in_array($record->pipeline_status, [PaymentSchedulingConstants::STATUS_BORRADOR])) {
            $this->Flash->error('Solo se puede importar Excel en estado Borrador.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        /** @var \Laminas\Diactoros\UploadedFile $file */
        $file = $this->request->getUploadedFile('excel_file');
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error('No se recibió un archivo válido.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        $result = $this->importService->parseExcel($tmpPath);

        // Guardar resultados en sesión para confirmar
        $this->request->getSession()->write("import_preview_{$id}", $result);

        return $this->redirect(['action' => 'previewImport', $id]);
    }

    #[Permission(action: 'add')]
    public function previewImport($id = null)
    {
        $record = $this->PaymentSchedulings->get($id);
        $result = $this->request->getSession()->read("import_preview_{$id}");

        if (!$result) {
            $this->Flash->error('No hay datos de importación pendientes.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $this->set(compact('record', 'result'));
    }

    #[Permission(action: 'add')]
    public function confirmImport($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        $result = $this->request->getSession()->read("import_preview_{$id}");
        if (!$result || empty($result['valid'])) {
            $this->Flash->error('No hay datos válidos para importar.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        if ($this->schedulingService->linkItems($record->id, $result['valid'])) {
            $count = count($result['valid']);
            $this->Flash->success("{$count} factura(s) vinculadas correctamente.");
        } else {
            $this->Flash->error('Error al vincular las facturas.');
        }

        $this->request->getSession()->delete("import_preview_{$id}");

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[Permission(action: 'add')]
    public function addItem($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        if ($record->pipeline_status !== PaymentSchedulingConstants::STATUS_BORRADOR) {
            $this->Flash->error('Solo se pueden agregar facturas en estado Borrador.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $itemsTable = $this->fetchTable('PaymentSchedulingItems');
        $item = $itemsTable->newEntity([
            'payment_scheduling_id' => $id,
            'invoice_id' => $this->request->getData('invoice_id'),
            'banking_entity_id' => $this->request->getData('banking_entity_id'),
            'amount' => $this->request->getData('amount'),
        ]);

        if ($itemsTable->save($item)) {
            $this->Flash->success('Factura vinculada.');
        } else {
            $this->Flash->error('No se pudo vincular la factura.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[Permission(action: 'delete')]
    public function removeItem($id = null, $itemId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->PaymentSchedulings->get($id);

        if ($record->pipeline_status !== PaymentSchedulingConstants::STATUS_BORRADOR) {
            $this->Flash->error('Solo se pueden eliminar facturas en estado Borrador.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $itemsTable = $this->fetchTable('PaymentSchedulingItems');
        $item = $itemsTable->get($itemId);

        if ($itemsTable->delete($item)) {
            $this->Flash->success('Factura desvinculada.');
        } else {
            $this->Flash->error('No se pudo desvincular la factura.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[Permission(action: 'add')]
    public function uploadDocument($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se recibió ningún archivo válido.']);
            }
            $this->Flash->error('No se recibió un archivo válido.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $result = $this->documentService->uploadDocument(
            (int)$id,
            $file,
            (int)$this->_getCurrentUser()->id,
        );

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            $isPagada = $record->pipeline_status === PaymentSchedulingConstants::STATUS_PAGADA;
            $canDelete = !$isPagada;
            $deleteUrl = $canDelete
                ? Router::url(['action' => 'deleteDocument', $id, $result->id])
                : null;

            return $this->_jsonResponse([
                'success' => true,
                'document' => $this->_buildDocumentPayload($result, $canDelete, $deleteUrl),
            ]);
        }

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Soporte subido correctamente.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[Permission(action: 'delete')]
    public function deleteDocument($id = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);

        $record = $this->PaymentSchedulings->get($id);
        if ($record->pipeline_status === PaymentSchedulingConstants::STATUS_PAGADA) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se pueden eliminar soportes de una programación pagada.']);
            }
            $this->Flash->error('No se pueden eliminar soportes de una programación pagada.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $documentsTable = $this->fetchTable('PaymentSchedulingDocuments');
        $document = $documentsTable->find()
            ->where(['id' => $documentId, 'payment_scheduling_id' => $id])
            ->first();

        if (!$document) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'Soporte no encontrado.']);
            }
            $this->Flash->error('Soporte no encontrado.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $deleted = $this->documentService->deleteDocument((int)$documentId);

        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(
                $deleted
                    ? ['success' => true]
                    : ['success' => false, 'error' => 'No se pudo eliminar el soporte.'],
            );
        }

        if ($deleted) {
            $this->Flash->success('Soporte eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[Permission(action: 'edit')]
    public function addObservation($id = null)
    {
        return $this->_handleAddObservation(
            'PaymentSchedulingObservations',
            'payment_scheduling_id',
            $id,
            $this->_getCurrentUser(),
            fn() => $this->redirect(['action' => 'edit', $id]),
        );
    }
}

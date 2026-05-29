<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Attribute\PipelineAction;
use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Controller\Trait\ObservationControllerTrait;
use App\Model\Entity\PettyCashRecord;
use App\Service\PettyCashDocumentService;
use App\Service\PettyCashHistoryService;
use App\Service\PettyCashPipelineService;
use App\Service\Pipeline\PettyCash\Policy\PettyCashActionPolicy;
use App\Service\StructuredLogger;
use App\ViewModel\PettyCashAddViewModel;
use App\ViewModel\PettyCashEditViewModel;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;

class PettyCashRecordsController extends AppController
{
    use DocumentJsonPayloadTrait;
    use ObservationControllerTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private PettyCashPipelineService $pettyCashService;

    private PettyCashDocumentService $documentService;

    private PettyCashActionPolicy $actionPolicy;

    private PettyCashHistoryService $historyService;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->pettyCashService = $container->get(PettyCashPipelineService::class);
        $this->documentService = $container->get(PettyCashDocumentService::class);
        $this->actionPolicy = $container->get(PettyCashActionPolicy::class);
        $this->historyService = $container->get(PettyCashHistoryService::class);
    }

    private function _getCurrentUser(): \App\Model\Entity\User
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    /**
     * Build a human-readable error string from entity validation errors.
     *
     * @param string $prefix Base message shown to the user.
     * @param array $errors getErrors() output ([field => [rule => msg]]).
     * @return string
     */
    private function _formatRecordErrors(string $prefix, array $errors): string
    {
        $messages = [];
        foreach ($errors as $fieldErrors) {
            foreach ($fieldErrors as $msg) {
                if (is_string($msg) && $msg !== '') {
                    $messages[] = $msg;
                }
            }
        }

        if (empty($messages)) {
            return $prefix . ' Intente de nuevo.';
        }

        return $prefix . ' ' . implode(' ', $messages);
    }

    /**
     * "Mis Registros" — filtra por los status visibles del rol.
     */
    #[Permission(action: 'view')]
    public function index(): void
    {
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $visibleStatuses = $this->pettyCashService->getVisibleStatuses($roleId);

        $query = $this->PettyCashRecords->find()
            ->contain(['CreatedByUsers', 'Invoices'])
            ->orderBy(['PettyCashRecords.created' => 'DESC']);

        $query->where($this->_visibleStatusConditions('PettyCashRecords.status', $visibleStatuses));

        $this->_applyListFilters($query);

        $records = $this->paginate($query);
        $this->set(compact('records', 'visibleStatuses'));
    }

    /**
     * "Todos los Registros" — sin filtro de rol.
     */
    #[Permission(action: 'view')]
    public function all(): void
    {
        $query = $this->PettyCashRecords->find()
            ->contain(['CreatedByUsers', 'Invoices'])
            ->orderBy(['PettyCashRecords.created' => 'DESC']);

        $this->_applyListFilters($query);

        $records = $this->paginate($query);
        $visibleStatuses = [];
        $this->set(compact('records', 'visibleStatuses'));
        $this->render('index');
    }

    /**
     * "Pendientes" — registros activos (status != pagado).
     */
    #[Permission(action: 'view')]
    public function pending(): void
    {
        $query = $this->PettyCashRecords->find()
            ->contain(['CreatedByUsers', 'Invoices'])
            ->where(['PettyCashRecords.status !=' => PettyCashConstants::STATUS_PAGADA])
            ->orderBy(['PettyCashRecords.created' => 'DESC']);

        $this->_applyListFilters($query, skipStatus: true);

        $records = $this->paginate($query);
        $visibleStatuses = [];
        $this->set(compact('records', 'visibleStatuses'));
        $this->render('index');
    }

    /**
     * Apply text/date/status filters from the query string to a list query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query to apply filters to.
     * @param bool $skipStatus Whether to skip the status filter (used by `paid` which fixes status).
     * @return void
     */
    private function _applyListFilters(SelectQuery $query, bool $skipStatus = false): void
    {
        $params = $this->request->getQueryParams();

        if (!empty($params['code'])) {
            $query->where(['PettyCashRecords.code LIKE' => '%' . $params['code'] . '%']);
        }
        if (!$skipStatus && !empty($params['status'])) {
            $query->where(['PettyCashRecords.status' => $params['status']]);
        }
        if (!empty($params['date_from'])) {
            $query->where(['PettyCashRecords.created >=' => $params['date_from']]);
        }
        if (!empty($params['date_to'])) {
            $query->where(['PettyCashRecords.created <=' => $params['date_to'] . ' 23:59:59']);
        }
    }

    #[Permission(action: 'view')]
    public function view($id = null): void
    {
        $record = $this->PettyCashRecords->get($id, contain: [
            'CreatedByUsers',
            'BankingEntities',
            'PaymentCreatedByUsers',
            'PaymentAuthorizedByUsers',
            'Invoices' => ['Providers'],
            'PettyCashDocuments' => [
                'UploadedByUsers',
                'sort' => ['PettyCashDocuments.created' => 'DESC'],
            ],
            'PettyCashObservations' => [
                'Users',
                'sort' => ['PettyCashObservations.created' => 'ASC'],
            ],
        ]);

        $this->set(compact('record'));
    }

    #[Permission(action: 'add')]
    public function add()
    {
        $record = $this->PettyCashRecords->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();

            $invoiceIds = array_map('intval', array_filter((array)($data['invoice_ids'] ?? [])));

            $record = $this->PettyCashRecords->patchEntity($record, [
                'operation_center_id' => $data['operation_center_id'] ?? null,
                'status' => PettyCashConstants::STATUS_AGRUPACION,
                'total_amount' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if ($this->PettyCashRecords->save($record)) {
                $this->historyService->recordStatusChange(
                    (int)$record->id,
                    '',
                    (string)$record->status,
                    (int)$user->id,
                );
                if (!empty($invoiceIds)) {
                    $errors = $this->pettyCashService->addInvoices($record, $invoiceIds);
                    foreach ($errors as $err) {
                        $this->Flash->warning($err);
                    }
                    if (empty($errors)) {
                        $this->_recordInvoicesLinked((int)$record->id, $invoiceIds);
                    }
                }

                $this->Flash->success('Registro de Caja Menor creado exitosamente.');

                return $this->redirect(['action' => 'edit', $record->id]);
            }

            $this->Flash->error($this->_formatRecordErrors(
                'No se pudo crear el registro.',
                $record->getErrors(),
            ));
        }

        $vm = new PettyCashAddViewModel(
            record: $record,
            availableInvoices: $this->pettyCashService
                ->getAvailableInvoices($this->request->getQueryParams())->all(),
            operationCenters: $this->fetchTable('OperationCenters')->find('codeList')->all(),
            providers: $this->fetchTable('Providers')->find('list')->orderBy(['name' => 'ASC'])->toArray(),
            groupFilters: $this->request->getQueryParams(),
        );

        $this->set(get_object_vars($vm));
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $record = $this->PettyCashRecords->get($id, contain: [
            'CreatedByUsers',
            'OperationCenters',
            'Invoices' => ['Providers', 'OperationCenters'],
            'PettyCashDocuments' => [
                'UploadedByUsers',
                'sort' => ['PettyCashDocuments.created' => 'DESC'],
            ],
            'PettyCashObservations' => [
                'Users',
                'sort' => ['PettyCashObservations.created' => 'ASC'],
            ],
            'BankingEntities',
            'PaymentCreatedByUsers',
            'PaymentAuthorizedByUsers',
        ]);

        if ($this->request->is(['patch', 'post', 'put'])) {
            if (!$this->_ensureExpectedStatus($record->status)) {
                return $this->redirect(['action' => 'edit', $id]);
            }

            $user = $this->_getCurrentUser();

            $result = $this->pettyCashService->saveAndAdvance(
                $record,
                (int)$user->role_id,
                (int)$user->id,
                $this->request->getData(),
            );

            if (!$result->success) {
                foreach ($result->errors as $err) {
                    $this->Flash->error($err);
                }

                return $this->redirect(['action' => 'edit', $id]);
            }

            foreach ($result->data['linkWarnings'] ?? [] as $warning) {
                $this->Flash->warning($warning);
            }

            if (!empty($result->data['advanced'])) {
                $next = $result->data['nextStatus'] ?? '';
                $nextLabel = PettyCashConstants::STATUS_LABELS[$next] ?? $next;
                $this->Flash->success(sprintf('Registro guardado y avanzado a: %s', $nextLabel));

                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->success('Registro actualizado.');
            if (!empty($result->data['advanceWarning'])) {
                $this->Flash->warning($result->data['advanceWarning']);
            }

            return $this->redirect(['action' => 'edit', $id]);
        }

        $vm = $this->_buildEditViewModel($record);

        $this->set(get_object_vars($vm));
    }

    /**
     * Build the read-side view model for `edit()`. Encapsulates dropdown
     * loading, permission flags, advance/regress state, and synthetic payment
     * adaptation for the shared `payment_section` element.
     */
    private function _buildEditViewModel(PettyCashRecord $record): PettyCashEditViewModel
    {
        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);
        $roleId = (int)$user->role_id;

        $nextStatus = PettyCashConstants::TRANSITIONS[$record->status] ?? null;
        $advanceErrors = $nextStatus
            ? $this->pettyCashService->validateTransitionRequirements($record)
            : [];

        $canRegisterPayment = $this->actionPolicy->canRegisterPayment($record, $roleId);
        $canAuthorizePayment = $this->actionPolicy->canAuthorizePayment($record, $roleId);
        $canConfirmPayment = $this->actionPolicy->canConfirmPayment($record, $roleId);
        $canAdvance = $this->pettyCashService->denialReasonForAdvance($record, $roleId) === null;

        return new PettyCashEditViewModel(
            record: $record,
            currentStatus: $record->status,
            roleName: $roleName,
            canDeleteDocuments: $this->_checkPermission('petty_cash', 'delete'),
            canRegisterPayment: $canRegisterPayment,
            canAuthorizePayment: $canAuthorizePayment,
            canConfirmPayment: $canConfirmPayment,
            canRegress: $this->pettyCashService->denialReasonForRegress($record, $roleId) === null,
            canAdvance: $canAdvance,
            advanceErrors: $advanceErrors,
            nextStatus: $nextStatus,
            previousStatus: $this->pettyCashService->getPreviousStatus($record->status),
            regressLockMessage: $this->pettyCashService->getRegressionLockMessage($record),
            pipelineLabels: PettyCashConstants::STATUS_LABELS,
            syntheticPayments: $this->pettyCashService->buildSyntheticPayments($record),
            availableInvoices: $this->pettyCashService
                ->getAvailableInvoices($this->request->getQueryParams())->all(),
            operationCenters: $this->fetchTable('OperationCenters')->find('codeList')->all(),
            providers: $this->fetchTable('Providers')->find('list')->orderBy(['name' => 'ASC'])->toArray(),
            bankingEntities: $this->fetchTable('BankingEntities')->find('list')->toArray(),
            groupFilters: $this->request->getQueryParams(),
        );
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PETTY_CASH)]
    public function regressStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($id);
        $user = $this->_getCurrentUser();
        $reason = trim((string)$this->request->getData('reason', ''));

        $result = $this->pettyCashService->regress(
            $record,
            (int)$user->role_id,
            (int)$user->id,
            $reason,
        );

        if ($result->success) {
            $prev = $result->data['previousStatus'] ?? '';
            $prevLabel = PettyCashConstants::STATUS_LABELS[$prev] ?? $prev;
            $this->Flash->success(sprintf('Registro regresado a: %s', $prevLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo regresar el registro.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PETTY_CASH, step: PettyCashConstants::STATUS_TESORERIA)]
    public function registerPayment($id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();

        // The shared payment_section JS posts 'amount'; map to 'payment_amount'.
        $data = $this->request->getData();
        if (!isset($data['payment_amount']) && isset($data['amount'])) {
            $data['payment_amount'] = $data['amount'];
        }

        $result = $this->pettyCashService->registerPayment(
            (int)$id,
            (int)$user->role_id,
            $data,
            $user->id,
        );

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago registrado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo registrar el pago.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PETTY_CASH, step: PettyCashConstants::STATUS_AUTORIZACION_PAGO)]
    public function authorizePayment($id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();
        $result = $this->pettyCashService->authorizePayment(
            (int)$id,
            (int)$user->role_id,
            $user->id,
        );

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago autorizado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo autorizar.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * Tesorería confirma que el pago del record ya se ejecutó.
     * Avanza record y facturas hijas de verificacion_pago → pagada.
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PETTY_CASH, step: PettyCashConstants::STATUS_VERIFICACION_PAGO)]
    public function confirmPayment($id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();
        if (!$this->actionPolicy->canOperateStep((int)$user->role_id, PettyCashConstants::STATUS_VERIFICACION_PAGO)) {
            $this->Flash->error('No tiene permisos para confirmar este pago.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $result = $this->pettyCashService->confirmPayment((int)$id, (int)$user->id);

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago confirmado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo confirmar el pago.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PETTY_CASH, step: PettyCashConstants::STATUS_AUTORIZACION_PAGO)]
    public function rejectPayment($id = null)
    {
        $this->request->allowMethod(['post']);

        $reason = trim((string)$this->request->getData('reason'));
        if ($reason === '') {
            $this->Flash->error('Debe indicar un motivo de rechazo.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();
        $result = $this->pettyCashService->rejectPayment(
            (int)$id,
            (int)$user->role_id,
            $user->id,
            $reason,
        );

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago rechazado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo rechazar el pago.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[Permission(action: 'delete')]
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->PettyCashRecords->get($id);

        // Capturar datos auditables ANTES del hard-delete (el FK CASCADE de
        // petty_cash_histories impide registrar en la tabla de historial).
        $recordId = (int)$record->id;
        $recordCode = $record->code;
        $recordStatus = $record->status;

        $result = $this->pettyCashService->deleteRecord($record);

        if ($result->success) {
            (new StructuredLogger('PettyCashAudit'))->info('petty_cash_record_deleted', [
                'petty_cash_record_id' => $recordId,
                'code' => $recordCode,
                'status' => $recordStatus,
                'deleted_by' => (int)$this->_getCurrentUser()->id,
            ]);
            $this->Flash->success($result->data ?? 'Registro de Caja Menor eliminado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo eliminar el registro.');
        }

        return $this->redirect(['action' => 'index']);
    }

    #[Permission(action: 'edit')]
    public function removeInvoice($recordId = null, $invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($recordId);

        // Capturar el invoice_number ANTES de desvincular.
        $invoiceNumber = $this->_invoiceNumber((int)$invoiceId);

        if ($this->pettyCashService->removeInvoice($record, (int)$invoiceId)) {
            $this->historyService->recordFieldChange(
                (int)$recordId,
                'invoices_unlinked',
                $invoiceNumber,
                null,
                (int)$this->_getCurrentUser()->id,
            );
            $this->Flash->success('Factura removida del registro de caja menor.');
        } else {
            $this->Flash->error('No se puede remover facturas de un registro que no esté en Agrupación.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }

    #[Permission(action: 'edit')]
    public function linkInvoices($recordId = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($recordId);

        if (!$record->isAgrupacion()) {
            $this->Flash->error('Solo se pueden vincular facturas en estado Agrupación.');

            return $this->redirect(['action' => 'edit', $recordId]);
        }

        $invoiceIds = array_map('intval', array_filter((array)$this->request->getData('invoice_ids', [])));
        if (empty($invoiceIds)) {
            $this->Flash->warning('Seleccione al menos una factura para vincular.');

            return $this->redirect(['action' => 'edit', $recordId]);
        }

        $errors = $this->pettyCashService->addInvoices($record, $invoiceIds);
        if (empty($errors)) {
            $this->_recordInvoicesLinked((int)$record->id, $invoiceIds);
            $this->Flash->success(sprintf('%d factura(s) vinculada(s).', count($invoiceIds)));
        } else {
            foreach ($errors as $err) {
                $this->Flash->warning($err);
            }
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }

    /**
     * Registra en el historial del record de caja menor padre la vinculación
     * de un lote de facturas. Resuelve los invoice_number a partir de los ids y
     * guarda una única entrada resumen.
     *
     * @param int $recordId Record padre.
     * @param array<int> $invoiceIds Ids de las facturas vinculadas.
     */
    private function _recordInvoicesLinked(int $recordId, array $invoiceIds): void
    {
        if (empty($invoiceIds)) {
            return;
        }

        $numbers = $this->fetchTable('Invoices')->find()
            ->select(['invoice_number'])
            ->where(['id IN' => $invoiceIds])
            ->all()
            ->extract('invoice_number')
            ->toList();

        $clean = array_values(array_filter(array_map('strval', $numbers), fn($n) => $n !== ''));
        if (empty($clean)) {
            return;
        }

        $summary = count($clean) === 1
            ? $clean[0]
            : sprintf('%d facturas (%s)', count($clean), implode(', ', $clean));

        $this->historyService->recordFieldChange(
            $recordId,
            'invoices_linked',
            null,
            $summary,
            (int)$this->_getCurrentUser()->id,
        );
    }

    /**
     * Resuelve el invoice_number de una factura, o el id como fallback.
     */
    private function _invoiceNumber(int $invoiceId): string
    {
        $invoice = $this->fetchTable('Invoices')->find()
            ->select(['invoice_number'])
            ->where(['id' => $invoiceId])
            ->first();

        return (string)($invoice->invoice_number ?? $invoiceId);
    }

    #[Permission(action: 'add')]
    public function uploadDocument($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($id);

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se recibió ningún archivo válido.']);
            }
            $this->Flash->error('No se recibió ningún archivo válido.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $identity = $this->Authentication->getIdentity();
        $result = $this->documentService->uploadDocument(
            (int)$id,
            $file,
            $identity ? (int)$identity->getIdentifier() : null,
            $this->request->getData('document_type'),
        );

        if (!is_string($result)) {
            $this->historyService->recordFieldChange(
                (int)$id,
                'document',
                null,
                $result->file_name,
                (int)$this->_getCurrentUser()->id,
            );
        }

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            $canDelete = !$record->isPagada();
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
            $this->Flash->success('El soporte ha sido subido.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[Permission(action: 'edit')]
    public function addObservation($id = null)
    {
        return $this->_handleAddObservation(
            'PettyCashObservations',
            'petty_cash_record_id',
            $id,
            $this->_getCurrentUser(),
            fn() => $this->redirect(['action' => 'edit', $id]),
        );
    }

    #[Permission(action: 'delete')]
    public function deleteDocument($recordId = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->PettyCashRecords->get($recordId);

        if ($record->isPagada()) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se puede eliminar un soporte de un registro pagado.']);
            }
            $this->Flash->error('No se puede eliminar un soporte de un registro pagado.');

            return $this->redirect(['action' => 'edit', $recordId]);
        }

        $documentsTable = TableRegistry::getTableLocator()->get('PettyCashDocuments');
        $document = $documentsTable->find()
            ->where(['id' => $documentId, 'petty_cash_record_id' => $recordId])
            ->first();
        $fileName = $document?->file_name;

        $deleted = $this->documentService->deleteDocument((int)$documentId);

        if ($deleted) {
            $this->historyService->recordFieldChange(
                (int)$recordId,
                'document',
                $fileName,
                null,
                (int)$this->_getCurrentUser()->id,
            );
        }

        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(
                $deleted
                    ? ['success' => true]
                    : ['success' => false, 'error' => 'No se pudo eliminar el soporte.'],
            );
        }

        if ($deleted) {
            $this->Flash->success('El soporte ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }
}

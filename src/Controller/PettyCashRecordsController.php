<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Controller\Trait\ObservationControllerTrait;
use App\Service\PettyCashDocumentService;
use App\Service\PettyCashService;
use App\Service\PipelineAuthorizationService;
use Cake\I18n\Date;
use Cake\ORM\Query\SelectQuery;
use Cake\Routing\Router;
use DateTimeInterface;

class PettyCashRecordsController extends AppController
{
    use DocumentJsonPayloadTrait;
    use ObservationControllerTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private PettyCashService $pettyCashService;

    private PettyCashDocumentService $documentService;

    private PipelineAuthorizationService $pipelineAuth;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->pettyCashService = $container->get(PettyCashService::class);
        $this->documentService = $container->get(PettyCashDocumentService::class);
        $this->pipelineAuth = $container->get(PipelineAuthorizationService::class);
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    /**
     * "Mis Registros" — filtra por los status visibles del rol.
     */
    public function index(): void
    {
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        $visibleStatuses = $this->pettyCashService->getVisibleStatuses($roleName);

        $query = $this->PettyCashRecords->find()
            ->contain(['CreatedByUsers', 'Invoices'])
            ->order(['PettyCashRecords.created' => 'DESC']);

        if (!empty($visibleStatuses)) {
            $query->where(['PettyCashRecords.status IN' => $visibleStatuses]);
        }

        $this->_applyListFilters($query);

        $records = $this->paginate($query);
        $this->set(compact('records', 'visibleStatuses'));
    }

    /**
     * "Todos los Registros" — sin filtro de rol.
     */
    public function all(): void
    {
        $query = $this->PettyCashRecords->find()
            ->contain(['CreatedByUsers', 'Invoices'])
            ->order(['PettyCashRecords.created' => 'DESC']);

        $this->_applyListFilters($query);

        $records = $this->paginate($query);
        $visibleStatuses = [];
        $this->set(compact('records', 'visibleStatuses'));
        $this->render('index');
    }

    /**
     * "Pendientes" — registros activos (status != pagado).
     */
    public function pending(): void
    {
        $query = $this->PettyCashRecords->find()
            ->contain(['CreatedByUsers', 'Invoices'])
            ->where(['PettyCashRecords.status !=' => PettyCashConstants::STATUS_PAGADO])
            ->order(['PettyCashRecords.created' => 'DESC']);

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

    public function view($id = null): void
    {
        $record = $this->PettyCashRecords->get($id, contain: [
            'CreatedByUsers',
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
                if (!empty($invoiceIds)) {
                    $errors = $this->pettyCashService->addInvoices($record, $invoiceIds);
                    foreach ($errors as $err) {
                        $this->Flash->warning($err);
                    }
                }

                $this->Flash->success('Registro de Caja Menor creado exitosamente.');

                return $this->redirect(['action' => 'edit', $record->id]);
            }

            $this->Flash->error('No se pudo crear el registro. Intente de nuevo.');
        }

        $groupFilters = $this->request->getQueryParams();
        $availableInvoices = $this->pettyCashService->getAvailableInvoices($groupFilters)->all();
        $operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();
        $this->set(compact('record', 'availableInvoices', 'operationCenters', 'groupFilters'));
    }

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

            $data = $this->request->getData();
            $patchData = [];

            // Notes: editable in agrupacion and contabilidad
            if ($record->isAgrupacion() || $record->isContabilidad()) {
                $patchData['notes'] = $data['notes'] ?? $record->notes;
            }

            // Accounting fields: editable in contabilidad
            if ($record->isContabilidad()) {
                $isAccrued = !empty($data['accrued']);
                $patchData['accrued'] = $isAccrued;
                if ($isAccrued) {
                    $submittedDate = !empty($data['accrual_date']) ? $data['accrual_date'] : null;
                    if (empty($submittedDate)) {
                        $this->Flash->error('La fecha de causación es requerida cuando el registro está marcado como causado.');
                        $this->redirect(['action' => 'edit', $id]);

                        return;
                    }
                    $patchData['accrual_date'] = $submittedDate;
                } else {
                    $patchData['accrual_date'] = null;
                }
                $patchData['ready_for_payment'] = $data['ready_for_payment'] ?? null;
            }

            if (!empty($patchData)) {
                $record = $this->PettyCashRecords->patchEntity($record, $patchData);
                $this->PettyCashRecords->save($record);
            }

            // Add invoices (only in agrupacion)
            if ($record->isAgrupacion() && !empty($data['invoice_ids'])) {
                $invoiceIds = array_map('intval', array_filter((array)$data['invoice_ids']));
                $errors = $this->pettyCashService->addInvoices($record, $invoiceIds);
                foreach ($errors as $err) {
                    $this->Flash->warning($err);
                }
            }

            // Try to advance automatically (save + advance unified)
            $user = $this->_getCurrentUser();
            $roleName = $this->_getUserRoleName($user);
            $canAdvance = !$record->isPagado()
                && $this->pettyCashService->canAdvance(
                    (int)$user->role_id,
                    $roleName,
                    $record->status,
                );
            $advanced = false;
            if ($canAdvance) {
                $result = $this->pettyCashService->advanceStatus(
                    $record,
                    (int)$user->role_id,
                    $roleName,
                    $user->id,
                );
                if ($result['success']) {
                    $advanced = true;
                    $nextLabel = PettyCashConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
                    $this->Flash->success(sprintf('Registro guardado y avanzado a: %s', $nextLabel));
                } else {
                    $this->Flash->success('Registro actualizado.');
                    $this->Flash->warning($result['error']);
                }
            } else {
                $this->Flash->success('Registro actualizado.');
            }

            return $this->redirect(['action' => $advanced ? 'index' : 'edit', ...($advanced ? [] : [$id])]);
        }

        // Compute advance errors for the view (to decide button label)
        $nextStatus = PettyCashConstants::TRANSITIONS[$record->status] ?? null;
        $advanceErrors = [];
        if ($nextStatus) {
            $advanceErrors = $this->pettyCashService->getTransitionErrors($record);
        }

        $groupFilters = $this->request->getQueryParams();
        $availableInvoices = $this->pettyCashService->getAvailableInvoices($groupFilters)->all();
        $operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();
        $canDeleteDocuments = $this->_checkPermission('petty_cash', 'delete');

        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);
        $bankingEntities = $this->fetchTable('BankingEntities')->find('list')->toArray();
        $roleId = (int)$user->role_id;
        $canRegisterPayment = $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            PettyCashConstants::STATUS_TESORERIA,
        );
        $canAuthorizePayment = $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            PettyCashConstants::STATUS_AUT_PAGO,
        );

        // Synthesize a pseudo-payment from record columns so the shared
        // payment_section element can render it. Caja Menor stores a single
        // bulk payment as columns on the record itself (not in a payments table).
        $syntheticPayments = [];
        if (!empty($record->banking_entity_id)) {
            $isAuthorized = $record->isPagado();
            $syntheticPayments[] = (object)[
                'id' => $record->id,
                'banking_entity' => $record->banking_entity,
                'amount' => $record->payment_amount,
                'payment_date' => $record->payment_date instanceof DateTimeInterface
                    ? $record->payment_date
                    : (is_string($record->payment_date) && $record->payment_date !== ''
                        ? new Date($record->payment_date)
                        : null),
                'status' => $isAuthorized
                    ? InvoiceConstants::PAYMENT_RECORD_AUTHORIZED
                    : InvoiceConstants::PAYMENT_RECORD_PENDING,
                'authorized' => $isAuthorized,
                'authorized_by_user' => $record->payment_authorized_by_user ?? null,
                'authorized_date' => $record->payment_authorized_date instanceof DateTimeInterface
                    ? $record->payment_authorized_date
                    : null,
                'created_by_user' => $record->payment_created_by_user ?? null,
                'rejection_reason' => null,
            ];
        }

        $canRegress = $this->pettyCashService->canRegress((int)$this->_getCurrentUser()->role_id, $roleName, $record->status);
        $previousStatus = $this->pettyCashService->getPreviousStatus($record->status);
        $regressLockMessage = $this->pettyCashService->getRegressionLockMessage($record);
        $pipelineLabels = PettyCashConstants::STATUS_LABELS;
        $currentStatus = $record->status;

        $this->set(compact(
            'record',
            'availableInvoices',
            'operationCenters',
            'canDeleteDocuments',
            'groupFilters',
            'nextStatus',
            'advanceErrors',
            'roleName',
            'bankingEntities',
            'canRegisterPayment',
            'canAuthorizePayment',
            'syntheticPayments',
            'canRegress',
            'previousStatus',
            'regressLockMessage',
            'pipelineLabels',
            'currentStatus',
        ));
    }

    public function advanceStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($id);

        if (!$this->_ensureExpectedStatus($record->status)) {
            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);

        $result = $this->pettyCashService->advanceStatus(
            $record,
            (int)$user->role_id,
            $roleName,
            $user->id,
        );

        if ($result['success']) {
            $nextLabel = PettyCashConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
            $this->Flash->success(sprintf('Registro avanzado a: %s', $nextLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result['error']);

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function regressStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($id);
        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);
        $reason = trim((string)$this->request->getData('reason', ''));

        $result = $this->pettyCashService->regress(
            $record,
            (int)$user->role_id,
            $roleName,
            (int)$user->id,
            $reason,
        );

        if ($result['success']) {
            $prevLabel = PettyCashConstants::STATUS_LABELS[$result['previousStatus']]
                ?? $result['previousStatus'];
            $this->Flash->success(sprintf('Registro regresado a: %s', $prevLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result['error']);

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function registerPayment($id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);

        // The shared payment_section JS posts 'amount'; map to 'payment_amount'.
        $data = $this->request->getData();
        if (!isset($data['payment_amount']) && isset($data['amount'])) {
            $data['payment_amount'] = $data['amount'];
        }

        $result = $this->pettyCashService->registerPayment(
            (int)$id,
            (int)$user->role_id,
            $roleName,
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

    public function authorizePayment($id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);
        $result = $this->pettyCashService->authorizePayment(
            (int)$id,
            (int)$user->role_id,
            $roleName,
            $user->id,
        );

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago autorizado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo autorizar.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function rejectPayment($id = null)
    {
        $this->request->allowMethod(['post']);

        $reason = trim((string)$this->request->getData('reason'));
        if ($reason === '') {
            $this->Flash->error('Debe indicar un motivo de rechazo.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);
        $result = $this->pettyCashService->rejectPayment(
            (int)$id,
            (int)$user->role_id,
            $roleName,
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

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->PettyCashRecords->get($id);

        $result = $this->pettyCashService->deleteRecord($record);

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Registro de Caja Menor eliminado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo eliminar el registro.');
        }

        return $this->redirect(['action' => 'index']);
    }

    public function removeInvoice($recordId = null, $invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($recordId);

        if ($this->pettyCashService->removeInvoice($record, (int)$invoiceId)) {
            $this->Flash->success('Factura removida del registro.');
        } else {
            $this->Flash->error('No se puede remover facturas de un registro que no esté en Agrupación.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }

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
            $this->Flash->success(sprintf('%d factura(s) vinculada(s).', count($invoiceIds)));
        } else {
            foreach ($errors as $err) {
                $this->Flash->warning($err);
            }
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }

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

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            $canDelete = !$record->isPagado();
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

    public function deleteDocument($recordId = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->PettyCashRecords->get($recordId);

        if ($record->isPagado()) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se puede eliminar un soporte de un registro pagado.']);
            }
            $this->Flash->error('No se puede eliminar un soporte de un registro pagado.');

            return $this->redirect(['action' => 'edit', $recordId]);
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
            $this->Flash->success('El soporte ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }
}

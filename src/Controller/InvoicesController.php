<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\EmployeeStatusConstants;
use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\ExcelService;
use App\Service\InvoiceApprovalService;
use App\Service\InvoiceDocumentService;
use App\Service\InvoiceFilterService;
use App\Service\InvoiceHistoryService;
use App\Service\InvoicePaymentService;
use App\Service\InvoicePipelineService;
use ArrayObject;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;

class InvoicesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private InvoicePipelineService $pipeline;
    private InvoiceFilterService $filterService;
    private InvoiceDocumentService $documentService;
    private InvoiceApprovalService $approvalService;
    private InvoicePaymentService $paymentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->pipeline = new InvoicePipelineService();
        $this->filterService = new InvoiceFilterService();
        $this->documentService = new InvoiceDocumentService();
        $this->approvalService = new InvoiceApprovalService();
        $this->paymentService = new InvoicePaymentService();
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    private function _getRoleName(): string
    {
        return $this->_getUserRoleName($this->_getCurrentUser());
    }

    public function index()
    {
        $roleName = $this->_getRoleName();
        $visibleStatuses = $this->pipeline->getVisibleStatuses($roleName);
        $userId = (int)$this->_getCurrentUser()->id;

        $conditions = !empty($visibleStatuses)
            ? ['Invoices.pipeline_status IN' => $visibleStatuses]
            : [];

        // Excluir facturas de Caja Menor que ya están en contabilidad o posterior
        $conditions[] = [
            'OR' => [
                'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
                'Invoices.pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            ],
        ];

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInvoiceQuery($conditions, $userId));

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
    }

    public function all()
    {
        $roleName = $this->_getRoleName();
        $userId = (int)$this->_getCurrentUser()->id;

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInvoiceQuery([], $userId));
        $visibleStatuses = [];

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
        $this->render('index');
    }

    public function rejected(): void
    {
        $roleName = $this->_getRoleName();
        $userId = (int)$this->_getCurrentUser()->id;

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInvoiceQuery([
            'Invoices.area_approval' => InvoiceConstants::APPROVAL_REJECTED,
        ], $userId));
        $visibleStatuses = [];

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
        $this->render('index');
    }

    public function overdue(): void
    {
        $roleName = $this->_getRoleName();
        $userId = (int)$this->_getCurrentUser()->id;

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInvoiceQuery([
            'Invoices.due_date <' => date('Y-m-d'),
            'Invoices.pipeline_status !=' => InvoiceConstants::STATUS_PAGADA,
        ], $userId));
        $visibleStatuses = [];

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
        $this->render('index');
    }

    public function view($id = null)
    {
        $this->fetchTable('InvoiceReads')->markAsRead((int)$id, (int)$this->_getCurrentUser()->id);

        $invoice = $this->Invoices->get($id, contain: [
            'Providers',
            'Employees',
            'OperationCenters',
            'ExpenseTypes',
            'CostCenters',
            'ConfirmedByUsers',
            'RegisteredByUsers',
            'ApproverUsers',
            'PettyCashRecords',
            'InvoiceHistories' => ['Users'],
            'InvoiceObservations' => [
                'Users',
                'sort' => ['InvoiceObservations.created' => 'ASC'],
            ],
            'InvoiceDocuments' => [
                'UploadedByUsers',
                'sort' => ['InvoiceDocuments.created' => 'DESC'],
            ],
            'InvoicePayments' => [
                'BankingEntities',
                'CreatedByUsers',
                'AuthorizedByUsers',
                'PaymentSchedulings',
                'PettyCashRecords',
                'sort' => ['InvoicePayments.payment_date' => 'ASC'],
            ],
        ]);

        $roleName = $this->_getRoleName();
        $isRejected = $this->pipeline->isRejected($invoice);
        $isApproved = $invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION && $invoice->area_approval === InvoiceConstants::APPROVAL_APPROVED;
        $isLockedByPettyCash = $this->pipeline->isLockedByPettyCash($invoice);
        $isLockedByScheduling = $this->pipeline->isLockedByPaidScheduling((int)$id);
        $isLocked = $isLockedByPettyCash || $isLockedByScheduling;
        $pipelineStatuses = InvoicePipelineService::STATUSES;
        $pipelineLabels = InvoicePipelineService::STATUS_LABELS;

        $documentsByStatus = [];
        foreach ($invoice->invoice_documents as $doc) {
            $documentsByStatus[$doc->pipeline_status][] = $doc;
        }

        $fieldLabels = InvoiceHistoryService::FIELD_LABELS;
        $this->set(compact('invoice', 'roleName', 'isRejected', 'isApproved', 'isLockedByPettyCash', 'isLockedByScheduling', 'isLocked', 'pipelineStatuses', 'pipelineLabels', 'documentsByStatus', 'fieldLabels'));
    }

    public function add()
    {
        $invoice = $this->Invoices->newEmptyEntity();
        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();
            $data['registered_by'] = $user->id;
            $data['pipeline_status'] = InvoiceConstants::STATUS_APROBACION;
            $data['registration_date'] = date('Y-m-d');

            $invoice = $this->Invoices->patchEntity($invoice, $data);
            if ($this->Invoices->save($invoice)) {
                $this->Flash->success(__('La factura ha sido guardada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar la factura. Intente de nuevo.'));
        }

        $this->set(compact('invoice'));
        $this->set($this->_getFormDropdowns());
    }

    public function edit($id = null)
    {
        $this->fetchTable('InvoiceReads')->markAsRead((int)$id, (int)$this->_getCurrentUser()->id);

        $invoice = $this->Invoices->get($id, contain: [
            'Providers',
            'Employees',
            'OperationCenters',
            'PettyCashRecords',
            'InvoiceObservations' => [
                'Users',
                'sort' => ['InvoiceObservations.created' => 'ASC'],
            ],
            'InvoiceDocuments' => [
                'UploadedByUsers',
                'sort' => ['InvoiceDocuments.created' => 'DESC'],
            ],
            'InvoicePayments' => [
                'BankingEntities',
                'CreatedByUsers',
                'AuthorizedByUsers',
                'PaymentSchedulings',
                'PettyCashRecords',
                'sort' => ['InvoicePayments.payment_date' => 'ASC'],
            ],
        ]);

        // Paid invoices are read-only for non-admin roles: redirect to view.
        if (
            $invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA
            && $this->_getRoleName() !== RoleConstants::ADMIN
        ) {
            return $this->redirect(['action' => 'view', $id]);
        }

        // Unified lock: petty cash or paid scheduling (non-admin only).
        if ($this->_getRoleName() !== RoleConstants::ADMIN) {
            $lockMessage = $this->pipeline->getEditLockMessage($invoice);
            if ($lockMessage !== null) {
                $this->Flash->warning($lockMessage);

                return $this->redirect(['action' => 'view', $id]);
            }
        }

        $roleName = $this->_getRoleName();
        $currentStatus = $invoice->pipeline_status;

        $editableFields = $this->pipeline->getEditableFields($roleName, $currentStatus);
        $canAdvance = $this->pipeline->canAdvance($roleName, $currentStatus);
        $visibleSections = $this->pipeline->getVisibleSections($roleName, $currentStatus);
        $collapsibleSections = $this->pipeline->getCollapsibleSections($roleName, $currentStatus);
        $isRejected = $this->pipeline->isRejected($invoice);
        $isApproved = $invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION && $invoice->area_approval === InvoiceConstants::APPROVAL_APPROVED;

        // Pre-compute advance errors for GET
        $advanceErrors = [];
        $nextStatus = null;
        if ($canAdvance && !$isRejected) {
            $rawErrors = $this->pipeline->validateTransitionRequirements($invoice, $currentStatus);
            $rules = $this->pipeline->getTransitionRules($currentStatus);
            $advanceErrors = $this->pipeline->filterAdvanceErrorsForRole($rawErrors, $rules, $roleName, $currentStatus);
            if (empty($rawErrors)) {
                $nextStatus = $this->pipeline->getNextStatus($currentStatus);
            }
        }

        if ($this->request->is(['patch', 'post', 'put'])) {
            $user = $this->_getCurrentUser();
            $result = $this->pipeline->saveAndAdvance(
                $invoice,
                $this->request->getData(),
                $roleName,
                $user->id,
                $this->_getBaseUrl(),
            );

            if ($result['saved']) {
                if ($result['advanced']) {
                    $nextLabel = InvoicePipelineService::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
                    $this->Flash->success(sprintf('Factura guardada y avanzada a: %s', $nextLabel));
                } else {
                    $this->Flash->success('La factura ha sido actualizada.');
                    $rules = $this->pipeline->getTransitionRules($currentStatus);
                    $filteredErrors = $this->pipeline->filterAdvanceErrorsForRole(
                        $result['advanceErrors'],
                        $rules,
                        $roleName,
                        $currentStatus,
                    );
                    foreach ($filteredErrors as $err) {
                        $this->Flash->warning($err);
                    }
                }

                $redirectAction = $result['advanced'] ? 'index' : 'edit';
                return $this->redirect(['action' => $redirectAction, ...($redirectAction === 'edit' ? [$id] : [])]);
            }

            $this->Flash->error('No se pudo guardar la factura. Verifique los datos e intente de nuevo.');
        }

        $pipelineStatuses = InvoicePipelineService::STATUSES;
        $pipelineLabels = InvoicePipelineService::STATUS_LABELS;

        $canDeleteDocuments = $this->_checkPermission('invoices', 'delete');

        // Multi-approver data
        $currentApprovals = $this->approvalService->getCurrentApprovals($invoice->id);
        $hasPendingApprovals = $this->approvalService->hasPendingApprovals($invoice->id);
        $hasAnyActiveApprovals = $this->approvalService->hasAnyActiveApprovals($invoice->id);
        $isApprovalEditableState = $currentStatus === InvoiceConstants::STATUS_APROBACION && !empty($editableFields);
        $canSendLinks = $isApprovalEditableState && !$hasAnyActiveApprovals;
        $canModifyApprovers = $isApprovalEditableState && $hasAnyActiveApprovals;

        $paymentsTotal = array_sum(array_map(
            fn($p) => (float)$p->amount,
            $invoice->invoice_payments ?? [],
        ));

        $this->set(compact(
            'invoice',
            'editableFields',
            'canAdvance',
            'canDeleteDocuments',
            'roleName',
            'pipelineStatuses',
            'pipelineLabels',
            'currentStatus',
            'visibleSections',
            'collapsibleSections',
            'isRejected',
            'isApproved',
            'advanceErrors',
            'nextStatus',
            'currentApprovals',
            'hasPendingApprovals',
            'canSendLinks',
            'canModifyApprovers',
            'paymentsTotal',
        ));
        $this->set($this->_getFormDropdowns());
    }

    public function advanceStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);

        if ($this->_getRoleName() !== RoleConstants::ADMIN) {
            $lockMessage = $this->pipeline->getEditLockMessage($invoice);
            if ($lockMessage !== null) {
                $this->Flash->error($lockMessage);

                return $this->redirect(['action' => 'view', $id]);
            }
        }

        $user = $this->_getCurrentUser();

        $result = $this->pipeline->advance($invoice, $this->_getRoleName(), $user->id);

        if ($result['success']) {
            $nextLabel = InvoicePipelineService::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
            $this->Flash->success(sprintf('Factura avanzada a: %s', $nextLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result['error']);

        return $this->redirect(['action' => 'edit', $id]);
    }

    private function _getBaseUrl(): string
    {
        $scheme = $this->request->getHeaderLine('X-Forwarded-Proto') ?: $this->request->scheme();

        return $scheme . '://' . $this->request->host();
    }

    public function addObservation($id = null)
    {
        $this->request->allowMethod(['post']);
        $user = $this->_getCurrentUser();

        $observationsTable = $this->fetchTable('InvoiceObservations');
        $observation = $observationsTable->newEntity([
            'invoice_id' => $id,
            'user_id' => $user->id,
            'message' => $this->request->getData('message'),
        ]);

        if ($this->_isJsonRequest()) {
            if ($observationsTable->save($observation)) {
                return $this->_jsonResponse([
                    'success' => true,
                    'observation' => [
                        'message' => $observation->message,
                        'user_name' => $user->full_name,
                        'created' => $observation->created->format('d/m/Y H:i'),
                    ],
                ]);
            }

            return $this->_jsonResponse(['success' => false, 'error' => 'No se pudo agregar la observación.']);
        }

        if ($observationsTable->save($observation)) {
            $this->Flash->success('Observación agregada.');
        } else {
            $this->Flash->error('No se pudo agregar la observación.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function export()
    {
        $roleName = $this->_getRoleName();
        $visibleStatuses = $this->pipeline->getVisibleStatuses($roleName);
        $pipelineLabels = InvoicePipelineService::STATUS_LABELS;

        $query = $this->Invoices->find()
            ->contain(['Providers', 'OperationCenters', 'ExpenseTypes', 'CostCenters'])
            ->order(['Invoices.registration_date' => 'DESC']);

        if (!empty($visibleStatuses)) {
            $query->where(['Invoices.pipeline_status IN' => $visibleStatuses]);
        }

        $query->formatResults(function ($results) use ($pipelineLabels) {
            return $results->map(function ($invoice) use ($pipelineLabels) {
                return new ArrayObject([
                    'Número Factura'      => $invoice->invoice_number ?? '',
                    'Tipo Documento'      => $invoice->document_type ?? '',
                    'Fecha Registro'      => $invoice->registration_date?->format('Y-m-d') ?? '',
                    'Fecha Emisión'       => $invoice->issue_date?->format('Y-m-d') ?? '',
                    'Fecha Vencimiento'   => $invoice->due_date?->format('Y-m-d') ?? '',
                    'Proveedor'           => $invoice->provider->name ?? '',
                    'Centro Operación'    => $invoice->operation_center->name ?? '',
                    'Tipo Gasto'          => $invoice->expense_type->name ?? '',
                    'Centro Costos'       => $invoice->cost_center->name ?? '',
                    'Detalle'             => $invoice->detail ?? '',
                    'Valor'               => $invoice->amount ?? 0,
                    'Validación DIAN'     => $invoice->dian_validation ?? '',
                    'Causada'             => $invoice->accrued ? 'Sí' : 'No',
                    'Fecha Causación'     => $invoice->accrual_date?->format('Y-m-d') ?? '',
                    'Lista para Pago'     => $invoice->ready_for_payment ?? '',
                    'Estado Pago'         => $invoice->payment_status ?? '',
                    'Fecha Pago Total'    => $invoice->full_payment_date?->format('Y-m-d') ?? '',
                    'Estado Pipeline'     => $pipelineLabels[$invoice->pipeline_status] ?? $invoice->pipeline_status ?? '',
                ]);
            });
        });

        $excelService = new ExcelService();
        $filePath = $excelService->exportCatalog('Facturas', $query);

        $response = $this->response->withFile($filePath, [
            'download' => true,
            'name' => 'facturas_' . date('Y-m-d') . '.xlsx',
        ]);

        register_shutdown_function(function () use ($filePath) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        });

        return $response;
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $invoice = $this->Invoices->get($id);
        if ($this->Invoices->delete($invoice)) {
            $this->Flash->success(__('La factura ha sido eliminada.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar la factura. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    private function _buildInvoiceQuery(array $conditions = [], ?int $userId = null): SelectQuery
    {
        $query = $this->Invoices->find()
            ->contain(['Providers', 'OperationCenters', 'ExpenseTypes', 'CostCenters', 'RegisteredByUsers']);

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        if ($userId !== null) {
            $query->selectAlso([
                'unread_observations' => "(
                    SELECT COUNT(*)
                    FROM invoice_observations io
                    LEFT JOIN invoice_reads ir
                        ON ir.invoice_id = io.invoice_id AND ir.user_id = $userId
                    WHERE io.invoice_id = Invoices.id
                      AND io.user_id != $userId
                      AND (ir.last_visited_at IS NULL OR io.created > ir.last_visited_at)
                )",
            ]);
        }

        $this->filterService->apply($query, $this->request->getQueryParams());

        return $query;
    }

    private function _getApprovalSummaries($invoices): array
    {
        $summaries = [];
        foreach ($invoices as $inv) {
            if ($inv->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
                $summaries[$inv->id] = $this->approvalService->getApprovalSummary($inv->id);
            }
        }

        return $summaries;
    }

    private function _getFilterDropdowns(): array
    {
        return [
            'providers' => $this->Invoices->Providers->find('list', limit: 200)->all(),
            'operationCenters' => $this->Invoices->OperationCenters->find('codeList')->all(),
            'expenseTypes' => $this->Invoices->ExpenseTypes->find('list', limit: 200)->all(),
        ];
    }

    private function _getFormDropdowns(): array
    {
        $activeApproverIds = $this->fetchTable('Approvers')
            ->find()
            ->select(['user_id'])
            ->where(['active' => true]);

        return [
            'providers' => $this->Invoices->Providers->find('list')->order(['Providers.name' => 'ASC'])->all(),
            'operationCenters' => $this->Invoices->OperationCenters->find('codeList')->all(),
            'expenseTypes' => $this->Invoices->ExpenseTypes->find('list', limit: 200)->all(),
            'costCenters' => $this->Invoices->CostCenters->find('codeList')->all(),
            'approvers' => $this->Invoices->ApproverUsers
                ->find('list', limit: 200)
                ->where(['ApproverUsers.id IN' => $activeApproverIds])
                ->all(),
            'employees' => $this->fetchTable('Employees')
                ->find()
                ->where(['Employees.employee_status_id' => EmployeeStatusConstants::ACTIVO])
                ->order(['Employees.first_name' => 'ASC'])
                ->limit(500)
                ->all()
                ->combine('id', function ($employee) {
                    return $employee->full_name . ' - ' . $employee->document_number;
                })
                ->toArray(),
            'bankingEntities' => $this->fetchTable('BankingEntities')->find('codeList')->all(),
        ];
    }

    public function uploadDocument($invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($invoiceId);

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se recibió ningún archivo válido.']);
            }
            $this->Flash->error(__('No se recibió ningún archivo válido.'));

            return $this->redirect(['action' => 'edit', $invoiceId]);
        }

        $identity = $this->Authentication->getIdentity();
        $result = $this->documentService->uploadDocument(
            (int)$invoiceId,
            $invoice->pipeline_status,
            $file,
            $identity ? (int)$identity->getIdentifier() : null,
            $this->request->getData('document_type'),
        );

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            return $this->_jsonResponse([
                'success' => true,
                'document' => [
                    'id' => $result->id,
                    'file_name' => $result->file_name,
                    'document_type' => $result->document_type,
                    'mime_type' => $result->mime_type,
                    'file_path' => $result->file_path,
                    'file_size' => $result->file_size,
                    'pipeline_status' => $result->pipeline_status,
                    'created' => $result->created->format('d/m/Y H:i'),
                ],
            ]);
        }

        if (is_string($result)) {
            $this->Flash->error(__($result));
        } else {
            $this->Flash->success(__('El soporte ha sido subido.'));
        }

        return $this->redirect(['action' => 'edit', $invoiceId]);
    }

    public function deleteDocument($invoiceId = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $invoice = $this->Invoices->get($invoiceId);

        $documentsTable = TableRegistry::getTableLocator()->get('InvoiceDocuments');
        $document = $documentsTable->get($documentId);

        if (!$this->documentService->canDeleteDocument($document, $invoice->pipeline_status)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se puede eliminar un soporte de un estado anterior.']);
            }
            $this->Flash->error(__('No se puede eliminar un soporte de un estado anterior.'));

            return $this->redirect(['action' => 'view', $invoiceId]);
        }

        $deleted = $this->documentService->deleteDocument((int)$documentId);

        if ($this->_isJsonRequest()) {
            if ($deleted) {
                return $this->_jsonResponse(['success' => true]);
            }

            return $this->_jsonResponse(['success' => false, 'error' => 'No se pudo eliminar el soporte.']);
        }

        if ($deleted) {
            $this->Flash->success(__('El soporte ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el soporte.'));
        }

        return $this->redirect(['action' => 'view', $invoiceId]);
    }

    /**
     * Sends approval links to the selected approvers. Fails if there are
     * pending approvals already active.
     */
    public function sendApprovalLinks($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);
        $user = $this->_getCurrentUser();
        $approverIds = (array)$this->request->getData('approver_ids');

        $result = $this->approvalService->sendApprovalLinks(
            $invoice,
            $approverIds,
            $this->_getBaseUrl(),
            (int)$user->id,
        );

        if (!empty($result['success'])) {
            $this->Flash->success('Enlaces de aprobación enviados.');
        } else {
            foreach ($result['errors'] ?? [] as $error) {
                $this->Flash->error($error);
            }
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * Replaces the current approver set. Requires a reason and invalidates
     * any active tokens.
     */
    public function modifyApprovers($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);
        $user = $this->_getCurrentUser();
        $reason = trim((string)$this->request->getData('reason'));
        $approverIds = (array)$this->request->getData('approver_ids');

        $result = $this->approvalService->modifyApprovers(
            $invoice,
            $approverIds,
            $reason,
            $this->_getBaseUrl(),
            (int)$user->id,
        );

        if (!empty($result['success'])) {
            $this->Flash->success('Aprobadores actualizados. Se enviaron los nuevos enlaces.');
        } else {
            foreach ($result['errors'] ?? [] as $error) {
                $this->Flash->error($error);
            }
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * Resets a rejected approval flow back to pending.
     */
    public function resetFlow($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);
        $user = $this->_getCurrentUser();

        $result = $this->approvalService->resetFlow($invoice, (int)$user->id);

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Flujo reiniciado.');
        } else {
            $this->Flash->error(is_array($result->errors) ? implode(' ', $result->errors) : (string)$result->errors);
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

}

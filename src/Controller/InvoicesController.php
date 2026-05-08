<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\EmployeeStatusConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\View\Presentation\InvoicePresentation;
use App\Model\Entity\Invoice;
use App\ViewModel\InvoiceAddViewModel;
use App\ViewModel\InvoiceEditViewModel;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Controller\Trait\ExcelWizardTrait;
use App\Controller\Trait\ObservationControllerTrait;
use App\Service\EmailLogService;
use App\Service\InvoiceApprovalService;
use App\Service\InvoiceDocumentService;
use App\Service\InvoiceFilterService;
use App\Service\InvoiceHistoryService;
use App\Service\InvoicePaymentService;
use App\Service\InvoicePipelineService;
use App\Service\PipelineAuthorizationService;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;

class InvoicesController extends AppController
{
    use DocumentJsonPayloadTrait;
    use ExcelWizardTrait;
    use ObservationControllerTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private InvoicePipelineService $pipeline;

    private InvoiceFilterService $filterService;

    private InvoiceDocumentService $documentService;

    private InvoiceApprovalService $approvalService;

    private InvoicePaymentService $paymentService;

    private PipelineAuthorizationService $pipelineAuth;

    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->pipeline = $container->get(InvoicePipelineService::class);
        $this->filterService = $container->get(InvoiceFilterService::class);
        $this->documentService = $container->get(InvoiceDocumentService::class);
        $this->approvalService = $container->get(InvoiceApprovalService::class);
        $this->paymentService = $container->get(InvoicePaymentService::class);
        $this->pipelineAuth = $container->get(PipelineAuthorizationService::class);
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
            'Invoices.pipeline_status NOT IN' => [
                InvoiceConstants::STATUS_PAGADA,
                InvoiceConstants::STATUS_LEGALIZADA,
            ],
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
        $pipelineStatuses = $this->pipeline->getPipelineStatusesFor($invoice->document_type);
        $pipelineLabels = InvoiceConstants::STATUS_LABELS;

        $documentsByStatus = [];
        foreach ($invoice->invoice_documents as $doc) {
            $documentsByStatus[$doc->pipeline_status][] = $doc;
        }

        $fieldLabels = InvoiceHistoryService::FIELD_LABELS;
        $this->set(compact('invoice', 'roleName', 'isRejected', 'isApproved', 'isLockedByPettyCash', 'isLockedByScheduling', 'isLocked', 'pipelineStatuses', 'pipelineLabels', 'documentsByStatus', 'fieldLabels'));
    }

    public function add()
    {
        if ($this->request->is('post')) {
            $vm = InvoiceAddViewModel::fromRequest(
                $this->Invoices,
                $this->request->getData(),
                (int)$this->_getCurrentUser()->id,
            );

            if ($this->Invoices->save($vm->invoice)) {
                $this->Flash->success(__('La factura ha sido guardada.'));

                return $this->_redirectForInvoice($vm->invoice, 'index');
            }
            $this->Flash->error(__('No se pudo guardar la factura. Intente de nuevo.'));
            $this->set('invoice', $vm->invoice);
            $this->set($this->_getFormDropdowns());

            return;
        }

        $vm = InvoiceAddViewModel::forForm($this->Invoices);
        $this->set('invoice', $vm->invoice);
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

        $terminalStatuses = [InvoiceConstants::STATUS_PAGADA, InvoiceConstants::STATUS_LEGALIZADA];
        if (in_array($invoice->pipeline_status, $terminalStatuses, true)) {
            return $this->_redirectForInvoice($invoice, 'view', $id);
        }

        $lockMessage = $this->pipeline->getEditLockMessage($invoice);
        if ($lockMessage !== null) {
            $this->Flash->warning($lockMessage);

            return $this->_redirectForInvoice($invoice, 'view', $id);
        }

        $roleName = $this->_getRoleName();
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $currentStatus = $invoice->pipeline_status;

        if ($this->request->is(['patch', 'post', 'put'])) {
            if (!$this->_ensureExpectedStatus($invoice->pipeline_status)) {
                return $this->_redirectForInvoice($invoice, 'edit', $id);
            }

            $result = $this->pipeline->saveAndAdvance(
                $invoice,
                $this->request->getData(),
                $roleId,
                $roleName,
                $this->_getCurrentUser()->id,
                $this->_getBaseUrl(),
            );

            if ($result->success) {
                $advanced = (bool)($result->data['advanced'] ?? false);
                $nextStatus = $result->data['nextStatus'] ?? null;
                $advanceErrors = $result->data['advanceErrors'] ?? [];

                if ($advanced) {
                    $nextLabel = InvoiceConstants::STATUS_LABELS[$nextStatus] ?? $nextStatus;
                    $this->Flash->success(sprintf('Factura guardada y avanzada a: %s', $nextLabel));
                } else {
                    $this->Flash->success('La factura ha sido actualizada.');
                    $rules = $this->pipeline->getTransitionRules($currentStatus);
                    $filteredErrors = $this->pipeline->filterAdvanceErrorsForRole(
                        $advanceErrors, $rules, $roleId, $roleName, $currentStatus,
                    );
                    foreach ($filteredErrors as $err) {
                        $this->Flash->warning($err);
                    }
                }

                return $advanced
                    ? $this->_redirectForInvoice($invoice, 'index')
                    : $this->_redirectForInvoice($invoice, 'edit', $id);
            }

            $this->Flash->error($result->firstError() ?? 'No se pudo guardar la factura. Verifique los datos e intente de nuevo.');
        }

        $vm = $this->_buildEditViewModel($invoice, $roleId, $roleName);
        $this->set('viewModel', $vm);
    }

    private function _buildEditViewModel(Invoice $invoice, int $roleId, string $roleName): InvoiceEditViewModel
    {
        $currentStatus = $invoice->pipeline_status;
        $editableFields = $this->pipeline->getEditableFields($roleId, $roleName, $currentStatus);
        $canAdvance = $this->pipeline->canAdvance($roleId, $roleName, $currentStatus, $invoice->document_type);
        $isRejected = $this->pipeline->isRejected($invoice);

        $advanceErrors = [];
        $nextStatus = null;
        if ($canAdvance && !$isRejected) {
            $rawErrors = $this->pipeline->validateTransitionRequirements($invoice, $currentStatus);
            $rules = $this->pipeline->getTransitionRules($currentStatus);
            $advanceErrors = $this->pipeline->filterAdvanceErrorsForRole($rawErrors, $rules, $roleId, $roleName, $currentStatus);
            if (empty($rawErrors)) {
                $nextStatus = $this->pipeline->getNextStatus($currentStatus);
            }
        }

        $canRegress = $this->pipeline->canRegress($roleId, $roleName, $currentStatus);
        $canConfirmPayment = $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_VERIFICACION_PAGO,
        );
        $hasAnyActiveApprovals = $this->approvalService->hasAnyActiveApprovals($invoice->id);
        $isApprovalEditableState = $currentStatus === InvoiceConstants::STATUS_APROBACION && !empty($editableFields);
        $dropdowns = $this->_getFormDropdowns();
        $emailLogService = $this->getContainer()->get(EmailLogService::class);

        return new InvoiceEditViewModel(
            invoice: $invoice,
            currentStatus: $currentStatus,
            roleName: $roleName,
            editableFields: $editableFields,
            canAdvance: $canAdvance,
            canDeleteDocuments: $this->_checkPermission('invoices', 'delete'),
            canRegress: $canRegress,
            canConfirmPayment: $canConfirmPayment,
            isRejected: $isRejected,
            isApproved: $invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION
                && $invoice->area_approval === InvoiceConstants::APPROVAL_APPROVED,
            visibleSections: $this->pipeline->getVisibleSections($roleId, $roleName, $currentStatus, $invoice->document_type),
            collapsibleSections: $this->pipeline->getCollapsibleSections($roleId, $roleName, $currentStatus),
            advanceErrors: $advanceErrors,
            nextStatus: $nextStatus,
            previousStatus: $this->pipeline->getPreviousStatus($currentStatus),
            regressLockMessage: $canRegress ? $this->pipeline->getRegressionLockMessage($invoice) : null,
            pipelineStatuses: $this->pipeline->getPipelineStatusesFor($invoice->document_type),
            pipelineLabels: InvoiceConstants::STATUS_LABELS,
            currentApprovals: $this->approvalService->getCurrentApprovals($invoice->id),
            hasPendingApprovals: $this->approvalService->hasPendingApprovals($invoice->id),
            canSendLinks: $isApprovalEditableState && !$hasAnyActiveApprovals,
            canModifyApprovers: $isApprovalEditableState && $hasAnyActiveApprovals,
            paymentsTotal: array_sum(array_map(fn($p) => (float)$p->amount, $invoice->invoice_payments ?? [])),
            providers: $dropdowns['providers'],
            operationCenters: $dropdowns['operationCenters'],
            expenseTypes: $dropdowns['expenseTypes'],
            costCenters: $dropdowns['costCenters'],
            approvers: $dropdowns['approvers'],
            employees: $dropdowns['employees'],
            bankingEntities: $dropdowns['bankingEntities'],
            emailLogs: $emailLogService->forEntity('invoice', (int)$invoice->id),
        );
    }

    public function advanceStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);

        if (!$this->_ensureExpectedStatus($invoice->pipeline_status)) {
            return $this->_redirectForInvoice($invoice, 'edit', $id);
        }

        $lockMessage = $this->pipeline->getEditLockMessage($invoice);
        if ($lockMessage !== null) {
            $this->Flash->error($lockMessage);

            return $this->_redirectForInvoice($invoice, 'view', $id);
        }

        $user = $this->_getCurrentUser();

        $result = $this->pipeline->advance($invoice, (int)$user->role_id, $this->_getRoleName(), $user->id);

        if ($result->success) {
            $nextStatus = $result->data['nextStatus'] ?? null;
            $nextLabel = InvoiceConstants::STATUS_LABELS[$nextStatus] ?? $nextStatus;
            $this->Flash->success(sprintf('Factura avanzada a: %s', $nextLabel));

            return $this->_redirectForInvoice($invoice, 'index');
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo avanzar la factura.');

        return $this->_redirectForInvoice($invoice, 'edit', $id);
    }

    public function regressStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);

        if (!$this->_ensureExpectedStatus($invoice->pipeline_status)) {
            return $this->_redirectForInvoice($invoice, 'edit', $id);
        }

        $user = $this->_getCurrentUser();
        $reason = trim((string)$this->request->getData('reason', ''));

        $result = $this->pipeline->regress(
            $invoice,
            (int)$user->role_id,
            $this->_getRoleName(),
            (int)$user->id,
            $reason,
        );

        if ($result->success) {
            $previousStatus = $result->data['previousStatus'] ?? null;
            $prevLabel = InvoiceConstants::STATUS_LABELS[$previousStatus] ?? $previousStatus;
            $this->Flash->success(sprintf('Factura regresada a: %s', $prevLabel));

            return $this->_redirectForInvoice($invoice, 'index');
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo regresar la factura.');

        return $this->_redirectForInvoice($invoice, 'edit', $id);
    }

    private function _getBaseUrl(): string
    {
        $scheme = $this->request->getHeaderLine('X-Forwarded-Proto') ?: $this->request->scheme();

        return $scheme . '://' . $this->request->host();
    }

    public function addObservation($id = null)
    {
        return $this->_handleAddObservation(
            'InvoiceObservations',
            'invoice_id',
            $id,
            $this->_getCurrentUser(),
            fn() => $this->_redirectForInvoice((int)$id, 'edit', $id),
        );
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $invoice = $this->Invoices->get($id, contain: ['InvoicePayments']);

        if ($invoice->pipeline_status !== InvoiceConstants::STATUS_APROBACION) {
            $this->Flash->error('Solo se pueden eliminar facturas en estado Aprobación.');

            return $this->_redirectForInvoice($invoice, 'view', $id);
        }

        if (!empty($invoice->invoice_payments)) {
            $this->Flash->error('No se puede eliminar una factura con pagos registrados.');

            return $this->_redirectForInvoice($invoice, 'view', $id);
        }

        if ($this->Invoices->delete($invoice)) {
            $this->Flash->success(__('La factura ha sido eliminada.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar la factura. Intente de nuevo.'));
        }

        return $this->_redirectForInvoice($invoice, 'index');
    }

    private function _buildInvoiceQuery(array $conditions = [], int $userId = 0): SelectQuery
    {
        $query = $this->Invoices->find()
            ->contain(['Providers', 'OperationCenters', 'ExpenseTypes', 'CostCenters', 'RegisteredByUsers'])
            ->where(['Invoices.document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO]);

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        if ($userId > 0) {
            $uid = (int)$userId;
            $subquery = "(
                SELECT COUNT(*)
                FROM invoice_observations io
                LEFT JOIN invoice_reads ir
                    ON ir.invoice_id = io.invoice_id AND ir.user_id = {$uid}
                WHERE io.invoice_id = Invoices.id
                  AND io.user_id != {$uid}
                  AND (ir.last_visited_at IS NULL OR io.created > ir.last_visited_at)
            )";

            $query->selectAlso(['unread_observations' => $subquery]);
        }

        $this->filterService->apply($query, $this->request->getQueryParams());

        $query->order(['Invoices.created' => 'DESC']);

        return $query;
    }

    private function _getApprovalSummaries($invoices): array
    {
        $ids = [];
        foreach ($invoices as $inv) {
            if ($inv->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
                $ids[] = (int)$inv->id;
            }
        }

        return $this->approvalService->getApprovalSummariesBatch($ids);
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
                ->where(['Employees.status' => EmployeeStatusConstants::ACTIVO])
                ->order(['Employees.first_name' => 'ASC', 'Employees.last_name1' => 'ASC'])
                ->all()
                ->combine('id', function ($employee) {
                    $doc = $employee->document_number ? ' - ' . $employee->document_number : '';

                    return $employee->full_name . $doc;
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

            return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
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

            $canDelete = $this->_checkPermission('invoices', 'delete')
                && $this->documentService->canDeleteDocument($result, $invoice->pipeline_status);
            $badgeColors = InvoicePresentation::STATUS_BADGES;
            $statusLabels = InvoiceConstants::STATUS_LABELS;
            $deleteUrl = $canDelete
                ? Router::url(['action' => 'deleteDocument', $invoice->id, $result->id])
                : null;

            return $this->_jsonResponse([
                'success' => true,
                'document' => $this->_buildDocumentPayload(
                    $result,
                    $canDelete,
                    $deleteUrl,
                    $badgeColors,
                    $statusLabels,
                ),
            ]);
        }

        if (is_string($result)) {
            $this->Flash->error(__($result));
        } else {
            $this->Flash->success(__('El soporte ha sido subido.'));
        }

        return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
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

            return $this->_redirectForInvoice($invoice, 'view', $invoiceId);
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

        return $this->_redirectForInvoice($invoice, 'view', $invoiceId);
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

        if ($result->success) {
            $this->Flash->success('Enlaces de aprobación enviados.');
        } else {
            foreach ($result->errors as $error) {
                $this->Flash->error($error);
            }
        }

        return $this->_redirectForInvoice($invoice, 'edit', $id);
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

        if ($result->success) {
            $this->Flash->success('Aprobadores actualizados. Se enviaron los nuevos enlaces.');
        } else {
            foreach ($result->errors as $error) {
                $this->Flash->error($error);
            }
        }

        return $this->_redirectForInvoice($invoice, 'edit', $id);
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

        return $this->_redirectForInvoice($invoice, 'edit', $id);
    }
}

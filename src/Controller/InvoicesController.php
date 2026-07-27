<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Attribute\PipelineAction;
use App\Constants\EmployeeStatusConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Controller\Trait\ExcelWizardTrait;
use App\Controller\Trait\ObservationControllerTrait;
use App\Model\Entity\Invoice;
use App\Service\AdvanceLegalizationApprovalGuard;
use App\Service\AdvanceLegalizationService;
use App\Service\Approval\ApprovalUrlBuilder;
use App\Service\AuthorizationService;
use App\Service\Dto\GroupReadinessReport;
use App\Service\EmailLogService;
use App\Service\InvoiceApprovalService;
use App\Service\InvoiceDocumentService;
use App\Service\InvoiceFilterService;
use App\Service\InvoiceHistoryService;
use App\Service\InvoicePaymentService;
use App\Service\InvoicePipelineService;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\Policy\InvoiceActionPolicy;
use App\Service\Pipeline\PettyCash\Guard\PettyCashGuard;
use App\Service\RefundApprovalGuard;
use App\Service\StructuredLogger;
use App\View\Presentation\InvoicePresentation;
use App\ViewModel\Invoice\InvoiceApprovalState;
use App\ViewModel\Invoice\InvoiceEditPermissions;
use App\ViewModel\Invoice\InvoiceFormDropdowns;
use App\ViewModel\InvoiceAddViewModel;
use App\ViewModel\InvoiceEditViewModel;
use App\ViewModel\InvoiceViewViewModel;
use Cake\Http\Response;
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

    private InvoiceActionPolicy $actionPolicy;

    private InvoiceHistoryService $historyService;

    /**
     * Resuelve los servicios del contenedor de dependencias.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->pipeline = $container->get(InvoicePipelineService::class);
        $this->filterService = $container->get(InvoiceFilterService::class);
        $this->documentService = $container->get(InvoiceDocumentService::class);
        $this->approvalService = $container->get(InvoiceApprovalService::class);
        $this->paymentService = $container->get(InvoicePaymentService::class);
        $this->actionPolicy = $container->get(InvoiceActionPolicy::class);
        $this->historyService = $container->get(InvoiceHistoryService::class);
    }

    /**
     * Usuario autenticado actual.
     *
     * @return object
     */
    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    /**
     * Nombre del rol del usuario autenticado.
     *
     * @return string
     */
    private function _getRoleName(): string
    {
        return $this->_getUserRoleName($this->_getCurrentUser());
    }

    /**
     * Bandeja de facturas operables por el rol.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $roleName = $this->_getRoleName();
        $user = $this->_getCurrentUser();
        $roleId = (int)$user->role_id;
        $userId = (int)$user->id;
        $visibleStatuses = $this->pipeline->getVisibleStatuses($roleId);

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInboxQuery([], $userId, $roleId));

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
    }

    /**
     * Listado de todas las facturas (sin filtro de bandeja por rol).
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function all()
    {
        $roleName = $this->_getRoleName();
        $userId = (int)$this->_getCurrentUser()->id;

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $query = $this->_buildInvoiceQuery([], $userId)
            ->contain(['PettyCashRecords', 'Refunds', 'Advance']);
        $invoices = $this->paginate($query);
        $visibleStatuses = [];

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
        $this->render('index');
    }

    /**
     * Listado de facturas rechazadas en aprobación de área.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function rejected(): void
    {
        $roleName = $this->_getRoleName();
        $user = $this->_getCurrentUser();
        $roleId = (int)$user->role_id;
        $userId = (int)$user->id;

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInboxQuery(
            ['Invoices.area_approval' => InvoiceConstants::APPROVAL_REJECTED],
            $userId,
            $roleId,
        ));
        $visibleStatuses = [];

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
        $this->render('index');
    }

    /**
     * Listado de facturas vencidas aún no pagadas ni legalizadas.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function overdue(): void
    {
        $roleName = $this->_getRoleName();
        $user = $this->_getCurrentUser();
        $roleId = (int)$user->role_id;
        $userId = (int)$user->id;

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInboxQuery([
            'Invoices.due_date <' => date('Y-m-d'),
            'Invoices.document_type IN' => InvoiceConstants::DOCTYPES_WITH_DUE_DATE,
            'Invoices.pipeline_status NOT IN' => [
                InvoiceConstants::STATUS_PAGADA,
                InvoiceConstants::STATUS_LEGALIZADA,
            ],
        ], $userId, $roleId));
        $visibleStatuses = [];

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
        $this->render('index');
    }

    /**
     * Detalle de una factura.
     *
     * @param string|null $id Factura id.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null)
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
            'Refunds',
            'Advance',
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
            'InvoiceApprovals' => [
                'Users',
                'sort' => ['InvoiceApprovals.created' => 'ASC'],
            ],
        ]);

        $roleName = $this->_getRoleName();
        $isRejected = $this->pipeline->isRejected($invoice);
        $isApproved = $invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION && $invoice->area_approval === InvoiceConstants::APPROVAL_APPROVED;
        $isLockedByPettyCash = $this->pipeline->isLockedByPettyCash($invoice);
        $isLockedByScheduling = $this->pipeline->isLockedByPaidScheduling((int)$id);
        $isLocked = $isLockedByPettyCash || $isLockedByScheduling;
        $pipelineStatuses = $this->pipeline->getPipelineStatusesFor($invoice->document_type, $invoice);
        $pipelineLabels = InvoiceConstants::STATUS_LABELS;

        // Bypass de Admin sobre locks de integridad (no es un permiso de pipeline,
        // es comportamiento legítimo del rol Administrador frente a bloqueos).
        $isAdmin = $roleName === AuthorizationService::ROLE_ADMIN;
        $userPermissions = $this->viewBuilder()->getVar('userPermissions') ?? [];
        $canShowEdit = !empty($userPermissions['invoices']['can_edit']) && ($isAdmin || !$isLocked);
        $showPettyCashLock = $isLockedByPettyCash && !$isAdmin;
        $showSchedulingLock = $isLockedByScheduling && !$isAdmin;

        $documentsByStatus = [];
        foreach ($invoice->invoice_documents as $doc) {
            $documentsByStatus[$doc->pipeline_status][] = $doc;
        }

        $fieldLabels = InvoiceHistoryService::FIELD_LABELS;
        $viewModel = new InvoiceViewViewModel($invoice, $isRejected, $isApproved, $documentsByStatus);
        $this->set(compact('viewModel', 'roleName', 'isRejected', 'isApproved', 'isLockedByPettyCash', 'isLockedByScheduling', 'isLocked', 'canShowEdit', 'showPettyCashLock', 'showSchedulingLock', 'pipelineStatuses', 'pipelineLabels', 'documentsByStatus', 'fieldLabels'));
    }

    /**
     * Crea una factura (opcionalmente vinculada a una legalización de anticipo).
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add()
    {
        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $data['registered_by'] = (int)$this->_getCurrentUser()->id;
            $data['pipeline_status'] = InvoiceConstants::STATUS_APROBACION;
            $data['registration_date'] = date('Y-m-d');
            // Documentos sin vencimiento real (Legalización, Anticipo, etc.) usan
            // la fecha de emisión como vencimiento para satisfacer el NOT NULL.
            if (empty($data['due_date']) && !empty($data['issue_date'])) {
                $data['due_date'] = $data['issue_date'];
            }

            // F3: creación vinculada — re-validar el advance_id del cliente.
            $advanceId = (int)($data['advance_id'] ?? 0);
            $leg = null;
            if ($advanceId > 0) {
                $service = $this->getContainer()->get(AdvanceLegalizationService::class);
                $leg = $service->legalizationInValidacion($advanceId);
                $linkable = in_array(
                    $data['document_type'] ?? '',
                    InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                    true,
                );
                if ($leg === null || !$linkable) {
                    $this->Flash->error(__('No se puede crear un comprobante vinculado a esta legalización.'));
                    $vm = new InvoiceAddViewModel($this->Invoices->patchEntity($this->Invoices->newEmptyEntity(), $data));
                    $this->set('invoice', $vm->invoice);
                    $this->set('advance', $leg !== null ? $this->Invoices->get($advanceId) : null);
                    $this->set($this->_getFormDropdowns());

                    return;
                }
            }

            $vm = new InvoiceAddViewModel($this->Invoices->patchEntity($this->Invoices->newEmptyEntity(), $data));

            if ($this->Invoices->save($vm->invoice)) {
                $this->historyService->recordStatusChange(
                    (int)$vm->invoice->id,
                    '',
                    (string)$vm->invoice->pipeline_status,
                    (int)$this->_getCurrentUser()->id,
                );
                if ($leg !== null) {
                    $this->getContainer()->get(AdvanceLegalizationService::class)
                        ->recordDirectLink($leg, $vm->invoice, (int)$this->_getCurrentUser()->id);
                    $this->Flash->success(__('El comprobante ha sido creado y vinculado.'));

                    // El destino depende del permiso: `legalization` exige
                    // `advances.can_edit`. Sin él, el usuario iría a un 403 justo
                    // después de guardar con éxito.
                    return $this->redirect([
                        'controller' => 'Advances',
                        'action' => $this->_checkPermission('advances', 'edit')
                            ? 'legalization'
                            : 'view',
                        $advanceId,
                    ]);
                }
                $this->Flash->success(__('La factura ha sido guardada.'));

                return $this->_redirectForInvoice($vm->invoice, 'index');
            }
            $this->Flash->error(__('No se pudo guardar la factura. Intente de nuevo.'));
            $this->set('invoice', $vm->invoice);
            $this->set('advance', $advanceId > 0 ? $this->Invoices->get($advanceId) : null);
            $this->set($this->_getFormDropdowns());

            return;
        }

        $advanceId = (int)$this->request->getQuery('advance_id');
        $advance = null;
        $entity = $this->Invoices->newEmptyEntity();
        if (
            $advanceId > 0
            && $this->getContainer()->get(AdvanceLegalizationService::class)
                ->legalizationInValidacion($advanceId) !== null
        ) {
            $advance = $this->Invoices->get($advanceId);
            $entity = $this->Invoices->patchEntity($entity, [
                'advance_id' => $advanceId,
                'operation_center_id' => $advance->operation_center_id,
            ]);
        }
        $vm = new InvoiceAddViewModel($entity);
        $this->set('invoice', $vm->invoice);
        $this->set('advance', $advance);
        $this->set($this->_getFormDropdowns());
    }

    /**
     * Edición y avance de una factura según el paso del pipeline.
     *
     * @param string|null $id Factura id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
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
                    $filteredErrors = $this->pipeline->filterAdvanceErrorsForRole(
                        $advanceErrors,
                        $roleId,
                        $currentStatus,
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

    /**
     * Construye el view-model de la vista de edición.
     *
     * @param \App\Model\Entity\Invoice $invoice Factura cargada.
     * @param int $roleId Rol del usuario actual.
     * @param string $roleName Nombre del rol del usuario actual.
     * @return \App\ViewModel\InvoiceEditViewModel
     */
    private function _buildEditViewModel(Invoice $invoice, int $roleId, string $roleName): InvoiceEditViewModel
    {
        $currentStatus = $invoice->pipeline_status;
        $editableFields = $this->pipeline->getEditableFields($roleId, $currentStatus);
        $advanceDenial = $this->pipeline->denialReasonForAdvance($invoice, $roleId);
        $canAdvance = $advanceDenial === null;
        $isRejected = $this->pipeline->isRejected($invoice);

        $advanceErrors = [];
        $nextStatus = null;
        if ($canAdvance) {
            $rawErrors = $this->pipeline->validateTransitionRequirements($invoice, $currentStatus);
            $advanceErrors = $this->pipeline->filterAdvanceErrorsForRole($rawErrors, $roleId, $currentStatus);
            if (empty($rawErrors)) {
                $nextStatus = $this->pipeline->getNextStatus(
                    $currentStatus,
                    $invoice->document_type,
                    $invoice->advance_id,
                );
            }
        }

        $canRegress = $this->pipeline->denialReasonForRegress($invoice, $roleId) === null;
        $canConfirmPayment = $this->actionPolicy->canConfirmPayment($invoice, $roleId);
        $canRegisterPayment = $this->actionPolicy->canRegisterPayment($invoice, $roleId);
        $canAuthorizePayment = $this->actionPolicy->canAuthorizePayment($invoice, $roleId);
        $hasAnyActiveApprovals = $this->approvalService->hasAnyActiveApprovals($invoice->id);
        $isApprovalEditableState = $currentStatus === InvoiceConstants::STATUS_APROBACION && !empty($editableFields);
        $dropdowns = $this->_getFormDropdowns();
        $emailLogService = $this->getContainer()->get(EmailLogService::class);

        $permissions = new InvoiceEditPermissions(
            canAdvance: $canAdvance,
            canDeleteDocuments: !$invoice->isInFinalState(),
            canRegress: $canRegress,
            canConfirmPayment: $canConfirmPayment,
            canRegisterPayment: $canRegisterPayment,
            canAuthorizePayment: $canAuthorizePayment,
            isRejected: $isRejected,
            isApproved: $invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION
                && $invoice->area_approval === InvoiceConstants::APPROVAL_APPROVED,
        );

        $approvalState = new InvoiceApprovalState(
            currentApprovals: $this->approvalService->getCurrentApprovals($invoice->id),
            hasPendingApprovals: $this->approvalService->hasPendingApprovals($invoice->id),
            canSendLinks: $isApprovalEditableState && !$hasAnyActiveApprovals,
            canModifyApprovers: $isApprovalEditableState && $hasAnyActiveApprovals,
        );

        $formDropdowns = new InvoiceFormDropdowns(
            providers: $dropdowns['providers'],
            operationCenters: $dropdowns['operationCenters'],
            expenseTypes: $dropdowns['expenseTypes'],
            costCenters: $dropdowns['costCenters'],
            approvers: $dropdowns['approvers'],
            employees: $dropdowns['employees'],
            bankingEntities: $dropdowns['bankingEntities'],
        );

        return new InvoiceEditViewModel(
            invoice: $invoice,
            currentStatus: $currentStatus,
            roleName: $roleName,
            editableFields: $editableFields,
            visibleSections: $this->pipeline->getVisibleSections($roleId, $currentStatus, $invoice->document_type, $invoice),
            advanceErrors: $advanceErrors,
            nextStatus: $nextStatus,
            previousStatus: $this->pipeline->getPreviousStatus($currentStatus),
            regressLockMessage: $canRegress ? $this->pipeline->getRegressionLockMessage($invoice) : null,
            pipelineStatuses: $this->pipeline->getPipelineStatusesFor($invoice->document_type, $invoice),
            pipelineLabels: InvoiceConstants::STATUS_LABELS,
            paymentsTotal: array_sum(array_map(fn($p) => (float)$p->amount, $invoice->invoice_payments ?? [])),
            emailLogs: $emailLogService->forEntity('invoice', (int)$invoice->id),
            permissions: $permissions,
            approvalState: $approvalState,
            dropdowns: $formDropdowns,
        );
    }

    /**
     * Regresa la factura al paso anterior del pipeline.
     *
     * @param string|null $id Factura id.
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES)]
    public function regressStatus(?string $id = null)
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

    /**
     * URL base para construir enlaces de aprobación.
     *
     * @return string
     */
    private function _getBaseUrl(): string
    {
        return ApprovalUrlBuilder::baseFromRequest($this->request);
    }

    /**
     * Agrega una observación a la factura.
     *
     * @param string|null $id Factura id.
     * @return \Cake\Http\Response
     */
    #[Permission(action: 'edit')]
    public function addObservation(?string $id = null)
    {
        return $this->_handleAddObservation(
            'InvoiceObservations',
            'invoice_id',
            $id,
            $this->_getCurrentUser(),
            fn() => $this->_redirectForInvoice((int)$id, 'edit', $id),
        );
    }

    /**
     * Elimina una factura en estado Aprobación sin pagos registrados.
     *
     * @param string|null $id Factura id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
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
            (new StructuredLogger('InvoiceAudit'))->info('invoice_deleted', [
                'invoice_id' => (int)$invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'pipeline_status' => $invoice->pipeline_status,
                'deleted_by' => (int)$this->_getCurrentUser()->id,
            ]);
            $this->Flash->success(__('La factura ha sido eliminada.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar la factura. Intente de nuevo.'));
        }

        return $this->_redirectForInvoice($invoice, 'index');
    }

    /**
     * Construye el query base de facturas (excluye Anticipos).
     *
     * @param array<string|int, mixed> $conditions Condiciones extra.
     * @param int $userId Usuario para el conteo de observaciones sin leer.
     * @return \Cake\ORM\Query\SelectQuery
     */
    private function _buildInvoiceQuery(array $conditions = [], int $userId = 0): SelectQuery
    {
        $query = $this->Invoices->find()
            ->contain([
                'Providers',
                'Employees',
                'OperationCenters',
                'ExpenseTypes',
                'CostCenters',
                'RegisteredByUsers',
                // Referencia (no contención): la factura sigue siendo del módulo
                // de Facturas; la programación solo agenda su pago.
                'PaymentSchedulingItems' => [
                    'PaymentSchedulings',
                    'sort' => ['PaymentSchedulingItems.id' => 'DESC'],
                ],
            ])
            // El Anticipo es el registro padre y vive en /advances.
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

        $query->orderBy(['Invoices.created' => 'DESC']);

        return $query;
    }

    /**
     * Query de bandeja: lo que el rol puede operar y no pertenece a otro módulo.
     * Base común de index(), rejected() y overdue().
     *
     * @param array<string|int, mixed> $conditions Condiciones extra de la vista.
     */
    private function _buildInboxQuery(array $conditions, int $userId, int $roleId): SelectQuery
    {
        $visibleStatuses = $this->pipeline->getVisibleStatuses($roleId);
        $conditions = array_merge(
            $conditions,
            $this->_visibleStatusConditions('Invoices.pipeline_status', $visibleStatuses),
        );

        return $this->_buildInvoiceQuery($conditions, $userId)->find('withoutParent');
    }

    /**
     * Resúmenes de aprobación de las facturas en estado Aprobación.
     *
     * @param iterable $invoices Facturas del listado.
     * @return array
     */
    private function _getApprovalSummaries(iterable $invoices): array
    {
        $ids = [];
        foreach ($invoices as $inv) {
            if ($inv->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
                $ids[] = (int)$inv->id;
            }
        }

        return $this->approvalService->getApprovalSummariesBatch($ids);
    }

    /**
     * Dropdowns para los filtros del listado.
     *
     * @return array
     */
    private function _getFilterDropdowns(): array
    {
        return [
            'providers' => $this->Invoices->Providers->find('list', limit: 200)->all(),
            'operationCenters' => $this->Invoices->OperationCenters->find('codeList')->all(),
            'expenseTypes' => $this->Invoices->ExpenseTypes->find('list', limit: 200)->all(),
        ];
    }

    /**
     * Dropdowns para el formulario de factura.
     *
     * @return array
     */
    private function _getFormDropdowns(): array
    {
        $activeApproverIds = $this->fetchTable('Approvers')
            ->find()
            ->select(['user_id'])
            ->where(['active' => true]);

        return [
            'providers' => $this->Invoices->Providers->find('list')->orderBy(['Providers.name' => 'ASC'])->all(),
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
                ->orderBy(['Employees.first_name' => 'ASC', 'Employees.last_name1' => 'ASC'])
                ->all()
                ->combine('id', function ($employee) {
                    $doc = $employee->document_number ? ' - ' . $employee->document_number : '';

                    return $employee->full_name . $doc;
                })
                ->toArray(),
            'bankingEntities' => $this->fetchTable('BankingEntities')->find('codeList')->all(),
        ];
    }

    /**
     * Sube un soporte a la factura.
     *
     * @param string|null $invoiceId Factura id.
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES)]
    public function uploadDocument(?string $invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($invoiceId);

        $gate = $this->_documentGate($invoice, 'subir');
        if ($gate !== null) {
            return $gate;
        }

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

        if (!is_string($result)) {
            $this->historyService->recordFieldChange(
                (int)$invoiceId,
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

            $canDelete = !$invoice->isInFinalState()
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

    /**
     * Elimina un soporte de la factura.
     *
     * @param string|null $invoiceId Factura id.
     * @param string|null $documentId Soporte id.
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES)]
    public function deleteDocument(?string $invoiceId = null, ?string $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $invoice = $this->Invoices->get($invoiceId);

        $gate = $this->_documentGate($invoice, 'eliminar');
        if ($gate !== null) {
            return $gate;
        }

        $documentsTable = TableRegistry::getTableLocator()->get('InvoiceDocuments');
        $document = $documentsTable->find()
            ->where(['id' => $documentId, 'invoice_id' => $invoiceId])
            ->first();

        if ($document === null) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'Soporte no encontrado.']);
            }
            $this->Flash->error(__('Soporte no encontrado.'));

            return $this->_redirectForInvoice($invoice, 'view', $invoiceId);
        }

        if (!$this->documentService->canDeleteDocument($document, $invoice->pipeline_status)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se puede eliminar un soporte de un estado anterior.']);
            }
            $this->Flash->error(__('No se puede eliminar un soporte de un estado anterior.'));

            return $this->_redirectForInvoice($invoice, 'view', $invoiceId);
        }

        $fileName = $document->file_name;
        $deleted = $this->documentService->deleteDocument((int)$documentId);

        if ($deleted) {
            $this->historyService->recordFieldChange(
                (int)$invoiceId,
                'document',
                $fileName,
                null,
                (int)$this->_getCurrentUser()->id,
            );
        }

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
     * Gate compartido de soportes: bloquea si la factura está en estado terminal
     * (409) o si el rol no puede operar el paso actual del pipeline (403).
     */
    private function _documentGate(Invoice $invoice, string $blockedActionLabel): ?Response
    {
        if ($invoice->isInFinalState()) {
            return $this->_documentGateError(
                $invoice,
                sprintf('No se puede %s un soporte de una factura en estado final.', $blockedActionLabel),
                409,
            );
        }

        $roleId = (int)$this->_getCurrentUser()->role_id;
        if (!$this->actionPolicy->canOperateStep($roleId, (string)$invoice->pipeline_status)) {
            return $this->_documentGateError(
                $invoice,
                'No tiene permisos para gestionar soportes en este paso.',
                403,
            );
        }

        return null;
    }

    /**
     * Construye la respuesta de error del gate de documentos. JSON con status
     * HTTP apropiado para AJAX, redirect con flash para POST tradicional.
     */
    private function _documentGateError(Invoice $invoice, string $message, int $statusCode): Response
    {
        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(['success' => false, 'error' => $message], $statusCode);
        }

        $this->Flash->error($message);

        return $this->_redirectForInvoice($invoice, 'edit', $invoice->id);
    }

    /**
     * Edición inline de `dian_validation` desde la tabla de hijas de un registro
     * padre — Reintegro / Caja Menor / Anticipo (spec 2026-07-14 §3.4). Evita
     * salir de la vista del padre para resolver el DIAN de una hija.
     *
     * Endpoint de mutación por AJAX: mismo gate que la edición directa
     * (can_edit del módulo + canOperate del paso + FieldAccessPolicy), más la
     * verificación de pertenencia al padre indicado (anti-IDOR) y el rechazo
     * explícito si la tabla del navegador está desactualizada (409).
     *
     * @param string|null $id ID de la factura hija.
     * @return \Cake\Http\Response JSON con el nuevo valor y el readiness del grupo.
     */
    #[Permission(action: 'edit')]
    public function updateDianInline(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);

        // 1. RBAC: el rol debe poder operar `aprobacion` y tener `dian_validation`
        // entre sus campos editables. Solo depende del rol, así que va primero:
        // un rol sin permiso no puede usar el endpoint como oráculo de estado.
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $editableFields = $this->pipeline->getEditableFields($roleId, InvoiceConstants::STATUS_APROBACION);
        if (
            !$this->actionPolicy->canOperateStep($roleId, InvoiceConstants::STATUS_APROBACION)
            || !in_array('dian_validation', $editableFields, true)
        ) {
            return $this->_jsonResponse(
                ['success' => false, 'error' => 'No tiene permisos para validar DIAN.'],
                403,
            );
        }

        // 2. Anti-IDOR: la factura debe pertenecer al padre indicado. `parent_field`
        // se valida contra la whitelist PARENT_FOREIGN_KEYS — nunca se interpola crudo.
        $parentField = $this->request->getData('parent_field');
        $parentId = $this->request->getData('parent_id');
        if (
            !is_string($parentField)
            || !in_array($parentField, InvoiceConstants::PARENT_FOREIGN_KEYS, true)
            || !is_numeric($parentId)
            || (int)$parentId <= 0
            || (int)($invoice->{$parentField} ?? 0) !== (int)$parentId
        ) {
            return $this->_jsonResponse(
                ['success' => false, 'error' => 'La factura no pertenece al registro indicado.'],
                404,
            );
        }
        $parentId = (int)$parentId;

        // 3. Solo se resuelve el DIAN en `aprobacion` (tabla stale → error
        // explícito, nunca una escritura silenciosa sobre un estado avanzado).
        if ($invoice->pipeline_status !== InvoiceConstants::STATUS_APROBACION) {
            return $this->_jsonResponse(
                ['success' => false, 'error' => 'La factura ya no está en Aprobación. Refresque la página.'],
                409,
            );
        }

        // 4. Valor válido y doctype con DIAN (el Recibo de Caja está exento).
        $newValue = $this->request->getData('dian_validation');
        if (!is_string($newValue) || !in_array($newValue, InvoiceConstants::DIAN_STATUSES, true)) {
            return $this->_jsonResponse(
                ['success' => false, 'error' => 'Valor de validación DIAN inválido.'],
                422,
            );
        }
        if (!DocumentTypePolicyFactory::requiresDianFor($invoice->document_type)) {
            return $this->_jsonResponse(
                ['success' => false, 'error' => 'Este tipo de documento no requiere validación DIAN.'],
                422,
            );
        }

        $oldValue = $invoice->dian_validation;
        $invoice->dian_validation = $newValue;
        if (!$this->Invoices->save($invoice)) {
            return $this->_jsonResponse(
                ['success' => false, 'error' => 'No se pudo guardar el cambio.'],
                500,
            );
        }

        if ($oldValue !== $newValue) {
            $this->historyService->recordFieldChange(
                (int)$invoice->id,
                'dian_validation',
                $oldValue,
                $newValue,
                (int)$this->_getCurrentUser()->id,
            );
        }

        $readiness = $this->_readinessForParent($parentField, $parentId);

        return $this->_jsonResponse([
            'success' => true,
            'dian_validation' => $newValue,
            'readiness' => $readiness === null ? null : [
                'dian_pending' => count($readiness->dianPending),
                'support_missing' => count($readiness->supportMissing),
                'blocked' => $readiness->isBlocked(),
            ],
        ]);
    }

    /**
     * Requisitos pendientes de las hijas del padre, para que el JS refresque el
     * checklist sin recargar. Misma fuente que el gate de avance del padre.
     *
     * @param string $parentField FK del padre (ya validada contra PARENT_FOREIGN_KEYS).
     * @param int $parentId ID del padre.
     * @return \App\Service\Dto\GroupReadinessReport|null Null si la FK no tiene guard asociado.
     */
    private function _readinessForParent(string $parentField, int $parentId): ?GroupReadinessReport
    {
        return match ($parentField) {
            'refund_id' => (new RefundApprovalGuard())->childRequirements($parentId),
            'petty_cash_record_id' => (new PettyCashGuard())->childRequirements($parentId),
            'advance_id' => (new AdvanceLegalizationApprovalGuard())->childRequirements($parentId),
            default => null,
        };
    }

    /**
     * Sends approval links to the selected approvers. Fails if there are
     * pending approvals already active.
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function sendApprovalLinks(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id, contain: ['Providers']);
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
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function modifyApprovers(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id, contain: ['Providers']);
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
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function resetFlow(?string $id = null)
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

<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Attribute\PipelineAction;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Controller\Trait\ObservationControllerTrait;
use App\Model\Entity\Refund;
use App\Service\Approval\ApprovalUrlBuilder;
use App\Service\Pipeline\Invoice\Policy\InvoiceFieldAccessPolicy;
use App\Service\Pipeline\Refund\Policy\RefundActionPolicy;
use App\Service\RefundApprovalGuard;
use App\Service\RefundApprovalService;
use App\Service\RefundDocumentService;
use App\Service\RefundHistoryService;
use App\Service\RefundPaymentService;
use App\Service\RefundPipelineService;
use App\Service\StructuredLogger;
use App\ValueObject\UserContext;
use App\ViewModel\RefundAddViewModel;
use App\ViewModel\RefundEditViewModel;
use App\ViewModel\RefundViewViewModel;
use Cake\Http\Response;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use DateTimeImmutable;

class RefundsController extends AppController
{
    use ObservationControllerTrait;
    use DocumentJsonPayloadTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private RefundPipelineService $refundService;
    private RefundPaymentService $paymentService;
    private RefundDocumentService $documentService;
    private RefundActionPolicy $actionPolicy;
    private RefundHistoryService $historyService;
    private RefundApprovalService $approvalService;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->refundService = $container->get(RefundPipelineService::class);
        $this->paymentService = $container->get(RefundPaymentService::class);
        $this->documentService = $container->get(RefundDocumentService::class);
        $this->actionPolicy = $container->get(RefundActionPolicy::class);
        $this->historyService = $container->get(RefundHistoryService::class);
        $this->approvalService = $container->get(RefundApprovalService::class);
    }

    /**
     * @return object
     */
    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    /**
     * @return string
     */
    private function _getBaseUrl(): string
    {
        return ApprovalUrlBuilder::baseFromRequest($this->request);
    }

    /**
     * Gate compartido entre uploadDocument/deleteDocument: verifica que el
     * reintegro no esté pagado y que el rol pueda operar el step actual.
     * Devuelve la Response apropiada (JSON con HTTP 403 o redirect con flash)
     * cuando el gate falla, o null cuando puede continuar.
     */
    private function _documentGate(Refund $record, string $blockedActionLabel): ?Response
    {
        if ($record->isPagada()) {
            return $this->_documentGateError(
                sprintf('No se puede %s un soporte de un reintegro pagado.', $blockedActionLabel),
                $record->id,
                statusCode: 409,
            );
        }

        $roleId = (int)$this->_getCurrentUser()->role_id;
        if (!$this->actionPolicy->canOperateStep($roleId, (string)$record->status)) {
            return $this->_documentGateError(
                'No tiene permisos para gestionar soportes en este paso.',
                $record->id,
                statusCode: 403,
            );
        }

        return null;
    }

    /**
     * Construye la respuesta de error del gate de documentos. JSON con status
     * HTTP apropiado para AJAX, redirect con flash para POST tradicional.
     */
    private function _documentGateError(string $message, int $refundId, int $statusCode): Response
    {
        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(
                ['success' => false, 'error' => $message],
                $statusCode,
            );
        }

        $this->Flash->error($message);

        return $this->redirect(['action' => 'edit', $refundId]);
    }

    /**
     * "Mis Registros" — filtra por los status visibles del rol.
     */
    #[Permission(action: 'view')]
    public function index(): void
    {
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $visibleStatuses = $this->refundService->getVisibleStatuses($roleId);

        $query = $this->Refunds->find()
            ->contain(['CreatedByUsers', 'BeneficiaryEmployees', 'BeneficiaryProviders', 'Invoices'])
            ->orderBy(['Refunds.created' => 'DESC']);

        $query->where($this->_visibleStatusConditions('Refunds.status', $visibleStatuses));

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
        $query = $this->Refunds->find()
            ->contain(['CreatedByUsers', 'BeneficiaryEmployees', 'BeneficiaryProviders', 'Invoices'])
            ->orderBy(['Refunds.created' => 'DESC']);

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
        $query = $this->Refunds->find()
            ->contain(['CreatedByUsers', 'BeneficiaryEmployees', 'BeneficiaryProviders', 'Invoices'])
            ->where(['Refunds.status !=' => RefundConstants::STATUS_PAGADA])
            ->orderBy(['Refunds.created' => 'DESC']);

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
            // Escapar wildcards SQL para evitar abuso (DoS por LIKE costoso,
            // bypass de filtro). El backslash debe escaparse primero.
            $code = (string)$params['code'];
            $escaped = strtr($code, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
            $query->where(['Refunds.code LIKE' => '%' . $escaped . '%']);
        }
        if (
            !$skipStatus
            && !empty($params['status'])
            && in_array($params['status'], array_keys(RefundConstants::STATUS_LABELS), true)
        ) {
            $query->where(['Refunds.status' => $params['status']]);
        }
        if (!empty($params['date_from']) && self::_isValidDate((string)$params['date_from'])) {
            $query->where(['Refunds.created >=' => $params['date_from'] . ' 00:00:00']);
        }
        if (!empty($params['date_to']) && self::_isValidDate((string)$params['date_to'])) {
            $query->where(['Refunds.created <=' => $params['date_to'] . ' 23:59:59']);
        }
    }

    /**
     * @param string $value
     * @return bool
     */
    private static function _isValidDate(string $value): bool
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }

    /**
     * @param string|null $id
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null): void
    {
        $record = $this->Refunds->get($id, contain: [
            'CreatedByUsers',
            'OperationCenters',
            'BeneficiaryEmployees',
            'BeneficiaryProviders',
            'BankingEntities',
            'PaymentCreatedByUsers',
            'PaymentAuthorizedByUsers',
            'Invoices' => ['Providers', 'Employees', 'InvoiceDocuments'],
            'RefundObservations' => [
                'Users',
                'sort' => ['RefundObservations.created' => 'ASC'],
            ],
            'RefundDocuments' => ['UploadedByUsers'],
        ]);

        $gates = $this->_childActionGates();
        $this->set('viewModel', new RefundViewViewModel(
            $record,
            (new RefundApprovalGuard())->childRequirements((int)$record->id),
            $gates['canResolveDian'],
            canUploadSupport: $gates['canUploadSupport'],
        ));
    }

    /**
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'add')]
    public function add()
    {
        $record = $this->Refunds->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();

            $beneficiaryType = $data['beneficiary_type'] ?? null;
            $beneficiaryEmployeeId = !empty($data['beneficiary_employee_id'])
                ? (int)$data['beneficiary_employee_id']
                : null;
            $beneficiaryProviderId = !empty($data['beneficiary_provider_id'])
                ? (int)$data['beneficiary_provider_id']
                : null;

            $record = $this->Refunds->patchEntity($record, [
                'operation_center_id' => $data['operation_center_id'] ?? null,
                'beneficiary_type' => $beneficiaryType ?: null,
                'beneficiary_employee_id' => $beneficiaryType === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE
                    ? $beneficiaryEmployeeId
                    : null,
                'beneficiary_provider_id' => $beneficiaryType === RefundConstants::BENEFICIARY_TYPE_PROVIDER
                    ? $beneficiaryProviderId
                    : null,
            ]);
            // Campos protegidos (no mass-assignable) se asignan directamente.
            $record->status = RefundConstants::STATUS_AGRUPACION;
            $record->total_amount = 0;
            $record->created_by = $user->id;

            if ($this->Refunds->save($record)) {
                $this->historyService->recordStatusChange(
                    (int)$record->id,
                    '',
                    (string)$record->status,
                    (int)$user->id,
                );
                $this->Flash->success('Reintegro creado exitosamente.');

                return $this->redirect(['action' => 'edit', $record->id]);
            }

            $this->Flash->error('No se pudo crear el registro. Intente de nuevo.');
        }

        [$employees, $providers] = $this->_loadBeneficiaryLists();
        $vm = new RefundAddViewModel(
            record: $record,
            employees: $employees,
            providers: $providers,
            operationCenters: $this->fetchTable('OperationCenters')->find('codeList')->all(),
        );
        $this->set('viewModel', $vm);
    }

    /**
     * @return array
     */
    private function _loadBeneficiaryLists(): array
    {
        $employeesTable = $this->fetchTable('Employees');
        $providersTable = $this->fetchTable('Providers');

        $employees = $employeesTable->find()
            ->select(['id', 'first_name', 'last_name1', 'last_name2'])
            ->orderBy(['first_name' => 'ASC'])
            ->all()
            ->combine(
                'id',
                fn($e) => trim(
                    ($e->first_name ?? '')
                    . ' ' . ($e->last_name1 ?? '')
                    . ' ' . ($e->last_name2 ?? ''),
                ),
            )
            ->toArray();

        $providers = $providersTable->find('list', keyField: 'id', valueField: 'name')
            ->orderBy(['name' => 'ASC'])
            ->toArray();

        return [$employees, $providers];
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
    {
        $record = $this->Refunds->get($id, contain: [
            'CreatedByUsers',
            'OperationCenters',
            'BeneficiaryEmployees',
            'BeneficiaryProviders',
            'Invoices' => ['Providers', 'Employees', 'OperationCenters', 'InvoiceDocuments'],
            'RefundObservations' => [
                'Users',
                'sort' => ['RefundObservations.created' => 'ASC'],
            ],
            'BankingEntities',
            'PaymentCreatedByUsers',
            'PaymentAuthorizedByUsers',
            'RefundDocuments' => ['UploadedByUsers'],
        ]);

        if ($this->request->is(['patch', 'post', 'put'])) {
            if (!$this->_ensureExpectedStatus($record->status)) {
                return $this->redirect(['action' => 'edit', $id]);
            }

            $user = $this->_getCurrentUser();

            $result = $this->refundService->saveAndAdvance(
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
                $nextLabel = RefundConstants::STATUS_LABELS[$next] ?? $next;
                $this->Flash->success(sprintf('Registro guardado y avanzado a: %s', $nextLabel));

                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->success('Registro actualizado.');
            if (!empty($result->data['advanceWarning'])) {
                $this->Flash->warning($result->data['advanceWarning']);
            }

            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();
        $vm = $this->_buildEditViewModel($record, $user);
        $this->set('viewModel', $vm);
    }

    /**
     * Gates para las acciones inline sobre las hijas de un reintegro (viven en
     * `aprobacion`). `canResolveDian` es de 3 partes: además de operar el step y
     * tener can_edit(invoices), el `InvoiceFieldAccessPolicy` del rol debe incluir
     * `dian_validation` (invariante FieldAccessPolicy rol-aware). NO igualar a
     * canUploadSupport. `updateDianInline` revalida server-side.
     *
     * @return array{canUploadSupport: bool, canResolveDian: bool}
     */
    private function _childActionGates(): array
    {
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $context = new UserContext($roleId);
        $canOperateAprobacion = $this->authFacade->canOperate(
            $context,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_APROBACION,
        );
        $canEditInvoices = $this->_checkPermission('invoices', 'edit');
        $fieldPolicy = new InvoiceFieldAccessPolicy($this->authFacade);
        $canResolveDian = $canOperateAprobacion && $canEditInvoices
            && in_array(
                'dian_validation',
                $fieldPolicy->getEditableFields($roleId, InvoiceConstants::STATUS_APROBACION),
                true,
            );

        return [
            'canUploadSupport' => $canOperateAprobacion && $canEditInvoices,
            'canResolveDian' => $canResolveDian,
        ];
    }

    /**
     * Builds the read-only view-model that `templates/Refunds/edit.php` consumes.
     *
     * @param \App\Model\Entity\Refund $record Loaded refund.
     * @param object $user Current user identity object.
     */
    private function _buildEditViewModel(Refund $record, object $user): RefundEditViewModel
    {
        $gates = $this->_childActionGates();
        $nextStatus = $this->refundService->getNextStatus($record->status);
        $advanceErrors = $nextStatus
            ? $this->refundService->validateTransitionRequirements($record)
            : [];

        $groupFilters = $this->request->getQueryParams();
        [$employees, $providers] = $this->_loadBeneficiaryLists();
        $roleName = $this->_getUserRoleName($user);
        $roleId = (int)$user->role_id;
        $userContext = $this->_userContext();

        $isAprobacion = $record->status === RefundConstants::STATUS_APROBACION;
        $currentApprovals = $isAprobacion ? $this->approvalService->getCurrentApprovals((int)$record->id) : [];
        $approvalSummary = $this->approvalService->getApprovalSummary((int)$record->id);
        $hasActive = $this->approvalService->hasAnyActiveApprovals((int)$record->id);
        $approvers = $this->fetchTable('Users')->find('list', keyField: 'id', valueField: 'full_name')
            ->where(['active' => true])->toArray();

        return new RefundEditViewModel(
            record: $record,
            currentStatus: $record->status,
            employees: $employees,
            providers: $providers,
            operationCenters: $this->fetchTable('OperationCenters')->find('codeList')->all(),
            bankingEntities: $this->fetchTable('BankingEntities')->find('list')->toArray(),
            availableInvoices: $this->refundService->getAvailableInvoices($groupFilters)->all(),
            groupFilters: $groupFilters,
            nextStatus: $nextStatus,
            advanceErrors: $advanceErrors,
            canAdvance: $this->refundService->denialReasonForAdvance($record, $roleId) === null,
            canRegress: $this->refundService->denialReasonForRegress($record, $roleId) === null,
            previousStatus: $this->refundService->getPreviousStatus($record->status),
            regressLockMessage: $this->refundService->getRegressionLockMessage($record),
            canRegisterPayment: $this->actionPolicy->canRegisterPayment($record, $roleId),
            canAuthorizePayment: $this->actionPolicy->canAuthorizePayment($record, $roleId),
            canConfirmPayment: $this->actionPolicy->canConfirmPayment($record, $roleId),
            syntheticPayments: $this->refundService->buildSyntheticPayments($record),
            roleName: $roleName,
            pipelineLabels: RefundConstants::STATUS_LABELS,
            currentApprovals: $currentApprovals,
            approvalSummary: $approvalSummary,
            approvers: $approvers,
            canSendLinks: $isAprobacion && !$hasActive,
            hasPendingApprovals: $approvalSummary['pending'] > 0,
            readiness: (new RefundApprovalGuard())->childRequirements((int)$record->id),
            canUploadSupport: $gates['canUploadSupport'],
            canResolveDian: $gates['canResolveDian'],
        );
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]
    public function advanceStatus(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($id);

        if (!$this->_ensureExpectedStatus($record->status)) {
            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();

        $result = $this->refundService->advance(
            $record,
            (int)$user->role_id,
            (int)$user->id,
        );

        if ($result->success) {
            $nextStatus = $result->data['nextStatus'];
            $nextLabel = RefundConstants::STATUS_LABELS[$nextStatus] ?? $nextStatus;
            $this->Flash->success(sprintf('Registro avanzado a: %s', $nextLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo avanzar el registro.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]
    public function regressStatus(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($id);
        $statusBeforeRegress = $record->status;

        if (!$this->_ensureExpectedStatus($record->status)) {
            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();
        $reason = trim((string)$this->request->getData('reason', ''));

        $result = $this->refundService->regress(
            $record,
            (int)$user->role_id,
            (int)$user->id,
            $reason,
        );

        if ($result->success) {
            if ($statusBeforeRegress === RefundConstants::STATUS_APROBACION) {
                $this->approvalService->supersedeAll((int)$id);
            }

            $previousStatus = $result->data['previousStatus'];
            $prevLabel = RefundConstants::STATUS_LABELS[$previousStatus] ?? $previousStatus;
            $this->Flash->success(sprintf('Registro regresado a: %s', $prevLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo regresar el registro.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'edit')]
    public function sendApprovalLinks(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($id);
        $user = $this->_getCurrentUser();

        if ($record->status !== RefundConstants::STATUS_APROBACION) {
            $this->Flash->error('Solo se envían enlaces en el estado Aprobación.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $result = $this->approvalService->sendApprovalLinks(
            $record,
            (array)$this->request->getData('approver_ids'),
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

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'edit')]
    public function modifyApprovers(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($id);
        $user = $this->_getCurrentUser();

        $result = $this->approvalService->modifyApprovers(
            $record,
            (array)$this->request->getData('approver_ids'),
            trim((string)$this->request->getData('reason')),
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

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS, step: RefundConstants::STATUS_TESORERIA)]
    public function registerPayment(?string $id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();

        // The shared payment_section JS posts 'amount'; map to 'payment_amount'.
        $data = $this->request->getData();
        if (!isset($data['payment_amount']) && isset($data['amount'])) {
            $data['payment_amount'] = $data['amount'];
        }

        $result = $this->paymentService->registerPayment(
            (int)$id,
            $data,
            (int)$user->id,
            (int)$user->role_id,
        );

        if ($result->success) {
            // registerPayment avanza el reintegro a autorizacion_pago.
            $this->Flash->success($result->data ?? 'Pago registrado.');

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo registrar el pago.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS, step: RefundConstants::STATUS_AUTORIZACION_PAGO)]
    public function authorizePayment(?string $id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();
        $result = $this->paymentService->authorizePayment(
            (int)$id,
            (int)$user->id,
            (int)$user->role_id,
        );

        if ($result->success) {
            // authorizePayment avanza el reintegro a verificacion_pago.
            $this->Flash->success($result->data ?? 'Pago autorizado.');

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo autorizar.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * Tesorería confirma que el pago del reintegro se ejecutó.
     * Avanza reintegro y facturas hijas de verificacion_pago → pagada.
     *
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS, step: RefundConstants::STATUS_VERIFICACION_PAGO)]
    public function confirmPayment(?string $id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();
        $result = $this->paymentService->confirmPayment((int)$id, (int)$user->id);

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago confirmado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo confirmar el pago.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS, step: RefundConstants::STATUS_AUTORIZACION_PAGO)]
    public function rejectPayment(?string $id = null)
    {
        $this->request->allowMethod(['post']);

        $reason = trim((string)$this->request->getData('reason'));
        if ($reason === '') {
            $this->Flash->error('Debe indicar un motivo de rechazo.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();
        $result = $this->paymentService->rejectPayment(
            (int)$id,
            (int)$user->id,
            $reason,
            (int)$user->role_id,
        );

        if ($result->success) {
            // rejectPayment regresa el reintegro a tesoreria.
            $this->Flash->success($result->data ?? 'Pago rechazado.');

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo rechazar el pago.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->Refunds->get($id);

        if (!$record->canBeDeleted()) {
            $this->Flash->error('Solo se pueden eliminar registros en estado Agrupación.');

            return $this->redirect(['action' => 'index']);
        }

        // Unlink invoices first
        $invoicesTable = $this->fetchTable('Invoices');
        $invoicesTable->updateAll(
            ['refund_id' => null],
            ['refund_id' => $record->id],
        );

        $refundId = (int)$record->id;
        $refundCode = $record->code;
        $refundStatus = $record->status;

        if ($this->Refunds->delete($record)) {
            (new StructuredLogger('RefundAudit'))->info('refund_deleted', [
                'refund_id' => $refundId,
                'code' => $refundCode,
                'status' => $refundStatus,
                'deleted_by' => (int)$this->_getCurrentUser()->id,
            ]);
            $this->Flash->success('Reintegro eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el registro.');
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * @param string|null $recordId
     * @param string|null $invoiceId
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'edit')]
    public function removeInvoice(?string $recordId = null, ?string $invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($recordId);

        // Capturar el invoice_number ANTES de desvincular.
        $invoiceNumber = $this->_invoiceNumber((int)$invoiceId);

        if ($this->refundService->removeInvoice($record, (int)$invoiceId)) {
            $this->historyService->recordFieldChange(
                (int)$recordId,
                'invoices_unlinked',
                $invoiceNumber,
                null,
                (int)$this->_getCurrentUser()->id,
            );
            $this->Flash->success('Factura removida del registro.');
        } else {
            $this->Flash->error('No se puede remover facturas de un registro que no esté en Agrupación.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }

    /**
     * @param string|null $recordId
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'edit')]
    public function linkInvoices(?string $recordId = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($recordId);

        if (!$record->isAgrupacion()) {
            $this->Flash->error('Solo se pueden vincular facturas en estado Agrupación.');

            return $this->redirect(['action' => 'edit', $recordId]);
        }

        $invoiceIds = array_map('intval', array_filter((array)$this->request->getData('invoice_ids', [])));
        if (empty($invoiceIds)) {
            $this->Flash->warning('Seleccione al menos una factura para vincular.');

            return $this->redirect(['action' => 'edit', $recordId]);
        }

        $errors = $this->refundService->addInvoices($record, $invoiceIds);
        if (empty($errors)) {
            $this->_recordInvoicesLinked((int)$record->id, $invoiceIds);

            // Una factura vinculada al grupo deja de usar el flujo individual;
            // se invalidan sus aprobaciones individuales activas (defensivo).
            TableRegistry::getTableLocator()->get('InvoiceApprovals')->updateAll(
                ['status' => InvoiceConstants::APPROVER_STATUS_SUPERSEDED, 'token_hash' => null, 'token_expires_at' => null],
                ['invoice_id IN' => $invoiceIds, 'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE],
            );

            $this->Flash->success(sprintf('%d factura(s) vinculada(s).', count($invoiceIds)));
        } else {
            foreach ($errors as $err) {
                $this->Flash->warning($err);
            }
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }

    /**
     * Registra en el historial del reintegro padre la vinculación de un lote
     * de facturas. Resuelve los invoice_number a partir de los ids y guarda una
     * única entrada resumen.
     *
     * @param int $refundId Reintegro padre.
     * @param array<int> $invoiceIds Ids de las facturas vinculadas.
     */
    private function _recordInvoicesLinked(int $refundId, array $invoiceIds): void
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
            $refundId,
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

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'edit')]
    public function addObservation(?string $id = null)
    {
        return $this->_handleAddObservation(
            'RefundObservations',
            'refund_id',
            $id,
            $this->_getCurrentUser(),
            fn() => $this->redirect(['action' => 'edit', $id]),
        );
    }

    /**
     * Sube un soporte al reintegro.
     *
     * Enforcement de RBAC: la acción usa `#[PipelineAction(pipeline: refunds)]`
     * sin `step` (dinámica), por lo que `AppController::_enforcePermission` NO
     * aplica gate CRUD de módulo. La validación se hace inline en `_documentGate()`,
     * que delega en `actionPolicy->canOperateStep($roleId, $record->status)` y
     * responde 403 si el rol no puede operar el step actual del reintegro.
     *
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]
    public function uploadDocument(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($id);

        $gate = $this->_documentGate($record, 'subir');
        if ($gate !== null) {
            return $gate;
        }

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            $msg = 'No se recibió ningún archivo válido.';
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => $msg], 400);
            }
            $this->Flash->error($msg);

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
                return $this->_jsonResponse(['success' => false, 'error' => $result], 400);
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

    /**
     * Elimina un soporte del reintegro.
     *
     * Enforcement de RBAC: la acción usa `#[PipelineAction(pipeline: refunds)]`
     * sin `step` (dinámica); `AppController::_enforcePermission` NO aplica gate
     * CRUD de módulo. La validación ocurre inline en `_documentGate()`: bloquea si
     * el reintegro está pagado (409) y exige `actionPolicy->canOperateStep` para el
     * step actual (403). El servicio además valida la pertenencia del documento al
     * refund (anti-IDOR: find filtrado por `id` + `refund_id`).
     *
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]
    public function deleteDocument(?string $refundId = null, ?string $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->Refunds->get($refundId);

        $gate = $this->_documentGate($record, 'eliminar');
        if ($gate !== null) {
            return $gate;
        }

        $documentsTable = TableRegistry::getTableLocator()->get('RefundDocuments');
        $document = $documentsTable->find()
            ->where(['id' => $documentId, 'refund_id' => $refundId])
            ->first();
        $fileName = $document?->file_name;

        $deleted = $this->documentService->deleteDocument((int)$documentId, (int)$refundId);

        if ($deleted) {
            $this->historyService->recordFieldChange(
                (int)$refundId,
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

            return $this->_jsonResponse(
                ['success' => false, 'error' => 'No se pudo eliminar el soporte.'],
                404,
            );
        }

        if ($deleted) {
            $this->Flash->success('El soporte ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $refundId]);
    }
}

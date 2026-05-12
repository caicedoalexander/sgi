<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Attribute\PipelineAction;
use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Controller\Trait\ObservationControllerTrait;
use App\Model\Entity\Refund;
use App\Service\RefundDocumentService;
use App\Service\RefundPaymentService;
use App\Service\RefundService;
use App\ViewModel\RefundAddViewModel;
use App\ViewModel\RefundEditViewModel;
use Cake\Http\Response;
use Cake\ORM\Query\SelectQuery;
use Cake\Routing\Router;

class RefundsController extends AppController
{
    use ObservationControllerTrait;
    use DocumentJsonPayloadTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private RefundService $refundService;
    private RefundPaymentService $paymentService;
    private RefundDocumentService $documentService;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->refundService = $container->get(RefundService::class);
        $this->paymentService = $container->get(RefundPaymentService::class);
        $this->documentService = $container->get(RefundDocumentService::class);
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    /**
     * True si el rol del usuario actual puede operar en el step indicado del
     * pipeline de reembolsos. Se usa para gates de upload/delete de soportes.
     */
    private function _canOperateRefundStep(string $step): bool
    {
        return $this->authFacade->canOperate(
            $this->_userContext(),
            PipelineStepConstants::PIPELINE_REFUNDS,
            $step,
        );
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

        if (!$this->_canOperateRefundStep($record->status)) {
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

    private static function _isValidDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }

    #[Permission(action: 'view')]
    public function view($id = null): void
    {
        $record = $this->Refunds->get($id, contain: [
            'CreatedByUsers',
            'OperationCenters',
            'BeneficiaryEmployees',
            'BeneficiaryProviders',
            'BankingEntities',
            'PaymentCreatedByUsers',
            'PaymentAuthorizedByUsers',
            'Invoices' => ['Providers'],
            'RefundObservations' => [
                'Users',
                'sort' => ['RefundObservations.created' => 'ASC'],
            ],
            'RefundDocuments' => ['UploadedByUsers'],
        ]);

        $this->set(compact('record'));
    }

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

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $record = $this->Refunds->get($id, contain: [
            'CreatedByUsers',
            'OperationCenters',
            'BeneficiaryEmployees',
            'BeneficiaryProviders',
            'Invoices' => ['Providers', 'OperationCenters'],
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

            $data = $this->request->getData();
            $patchData = [];

            // Beneficiary: editable only in agrupacion
            if ($record->isAgrupacion()) {
                $beneficiaryType = $data['beneficiary_type'] ?? null;
                $patchData['beneficiary_type'] = $beneficiaryType ?: null;
                $patchData['beneficiary_employee_id'] = $beneficiaryType === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE
                    && !empty($data['beneficiary_employee_id'])
                    ? (int)$data['beneficiary_employee_id']
                    : null;
                $patchData['beneficiary_provider_id'] = $beneficiaryType === RefundConstants::BENEFICIARY_TYPE_PROVIDER
                    && !empty($data['beneficiary_provider_id'])
                    ? (int)$data['beneficiary_provider_id']
                    : null;
            }

            // Accounting fields: editable in contabilidad
            if ($record->isContabilidad()) {
                $isAccrued = !empty($data['accrued']);
                $patchData['accrued'] = $isAccrued;
                if ($isAccrued) {
                    $submittedDate = !empty($data['accrual_date']) ? $data['accrual_date'] : null;
                    if (empty($submittedDate)) {
                        $this->Flash->error('La fecha de causación es requerida cuando el registro está marcado como causado.');

                        return $this->redirect(['action' => 'edit', $id]);
                    }
                    $patchData['accrual_date'] = $submittedDate;
                } else {
                    $patchData['accrual_date'] = null;
                }
                $patchData['ready_for_payment'] = $data['ready_for_payment'] ?? null;
            }

            if (!empty($patchData)) {
                $record = $this->Refunds->patchEntity($record, $patchData);
                if (!$this->Refunds->save($record)) {
                    $errors = [];
                    foreach ($record->getErrors() as $field => $fieldErrors) {
                        foreach ($fieldErrors as $msg) {
                            $errors[] = "$field: $msg";
                        }
                    }
                    $this->Flash->error(
                        'No se pudo guardar el registro.'
                        . (!empty($errors) ? ' ' . implode(', ', $errors) : ''),
                    );

                    return $this->redirect(['action' => 'edit', $id]);
                }
            }

            // Add invoices (only in agrupacion)
            if ($record->isAgrupacion() && !empty($data['invoice_ids'])) {
                $invoiceIds = array_map('intval', array_filter((array)$data['invoice_ids']));
                $errors = $this->refundService->addInvoices($record, $invoiceIds);
                foreach ($errors as $err) {
                    $this->Flash->warning($err);
                }
            }

            // Try to advance automatically (save + advance unified)
            $user = $this->_getCurrentUser();
            $advanced = false;
            if ($record->canAdvancePipeline()) {
                $result = $this->refundService->advanceStatus(
                    $record,
                    (int)$user->id,
                    (int)$user->role_id,
                );
                if ($result['success']) {
                    $advanced = true;
                    $nextLabel = RefundConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
                    $this->Flash->success(sprintf('Registro guardado y avanzado a: %s', $nextLabel));
                } else {
                    $this->Flash->success('Registro actualizado.');
                    $this->Flash->warning($result['error']);
                }
            } else {
                $this->Flash->success('Registro actualizado.');
            }

            if ($advanced) {
                return $this->redirect(['action' => 'index']);
            }

            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();
        $vm = $this->_buildEditViewModel($record, $user);
        $this->set('viewModel', $vm);
    }

    /**
     * Builds the read-only view-model that `templates/Refunds/edit.php` consumes.
     *
     * @param \App\Model\Entity\Refund $record Loaded refund.
     * @param object $user Current user identity object.
     */
    private function _buildEditViewModel(Refund $record, object $user): RefundEditViewModel
    {
        $nextStatus = RefundConstants::TRANSITIONS[$record->status] ?? null;
        $advanceErrors = $nextStatus
            ? $this->refundService->getTransitionErrors($record)
            : [];

        $groupFilters = $this->request->getQueryParams();
        [$employees, $providers] = $this->_loadBeneficiaryLists();
        $roleName = $this->_getUserRoleName($user);
        $roleId = (int)$user->role_id;
        $userContext = $this->_userContext();

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
            canRegress: $this->refundService->denialReasonForRegress($record, $roleId) === null,
            previousStatus: $this->refundService->getPreviousStatus($record->status),
            regressLockMessage: $this->refundService->getRegressionLockMessage($record),
            canRegisterPayment: $this->authFacade->canOperate(
                $userContext,
                PipelineStepConstants::PIPELINE_REFUNDS,
                RefundConstants::STATUS_TESORERIA,
            ),
            canAuthorizePayment: $this->authFacade->canOperate(
                $userContext,
                PipelineStepConstants::PIPELINE_REFUNDS,
                RefundConstants::STATUS_AUTORIZACION_PAGO,
            ),
            canConfirmPayment: $this->authFacade->canOperate(
                $userContext,
                PipelineStepConstants::PIPELINE_REFUNDS,
                RefundConstants::STATUS_VERIFICACION_PAGO,
            ),
            syntheticPayments: $this->refundService->buildSyntheticPayments($record),
            roleName: $roleName,
            pipelineLabels: RefundConstants::STATUS_LABELS,
        );
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]
    public function advanceStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($id);

        if (!$this->_ensureExpectedStatus($record->status)) {
            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();

        $result = $this->refundService->advanceStatus(
            $record,
            (int)$user->id,
            (int)$user->role_id,
        );

        if ($result['success']) {
            $nextLabel = RefundConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
            $this->Flash->success(sprintf('Registro avanzado a: %s', $nextLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result['error']);

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]
    public function regressStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($id);

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

        if ($result['success']) {
            $prevLabel = RefundConstants::STATUS_LABELS[$result['previousStatus']]
                ?? $result['previousStatus'];
            $this->Flash->success(sprintf('Registro regresado a: %s', $prevLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result['error']);

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS, step: RefundConstants::STATUS_TESORERIA)]
    public function registerPayment($id = null)
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
            $this->Flash->success($result->data ?? 'Pago registrado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo registrar el pago.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS, step: RefundConstants::STATUS_AUTORIZACION_PAGO)]
    public function authorizePayment($id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();
        $result = $this->paymentService->authorizePayment(
            (int)$id,
            (int)$user->id,
            (int)$user->role_id,
        );

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago autorizado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo autorizar.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * Tesorería confirma que el pago del reintegro se ejecutó.
     * Avanza reintegro y facturas hijas de verificacion_pago → pagada.
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS, step: RefundConstants::STATUS_VERIFICACION_PAGO)]
    public function confirmPayment($id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();
        if (
            !$this->authFacade->canOperate(
                $this->_userContext(),
                PipelineStepConstants::PIPELINE_REFUNDS,
                RefundConstants::STATUS_VERIFICACION_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para confirmar este pago.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $result = $this->paymentService->confirmPayment((int)$id, (int)$user->id);

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago confirmado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo confirmar el pago.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS, step: RefundConstants::STATUS_AUTORIZACION_PAGO)]
    public function rejectPayment($id = null)
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

        if ($this->Refunds->delete($record)) {
            $this->Flash->success('Reintegro eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el registro.');
        }

        return $this->redirect(['action' => 'index']);
    }

    #[Permission(action: 'edit')]
    public function removeInvoice($recordId = null, $invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($recordId);

        if ($this->refundService->removeInvoice($record, (int)$invoiceId)) {
            $this->Flash->success('Factura removida del registro.');
        } else {
            $this->Flash->error('No se puede remover facturas de un registro que no esté en Agrupación.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }

    #[Permission(action: 'edit')]
    public function linkInvoices($recordId = null)
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
            $this->Flash->success(sprintf('%d factura(s) vinculada(s).', count($invoiceIds)));
        } else {
            foreach ($errors as $err) {
                $this->Flash->warning($err);
            }
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }

    #[Permission(action: 'edit')]
    public function addObservation($id = null)
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
     * Doble enforcement de RBAC (defensa en profundidad):
     * 1. `AppController::_enforcePermission` ya valida `refunds.can_create`
     *    para esta acción (mapeo `uploadDocument` → `add` en
     *    `_actionToPermission`). Sin ese permiso de módulo el request es
     *    rechazado con 403 antes de entrar al método.
     * 2. `_canOperateRefundStep` valida que el rol tenga permiso de pipeline
     *    para operar en el step actual del reintegro.
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]
    public function uploadDocument($id = null)
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
     * Doble enforcement de RBAC (defensa en profundidad):
     * 1. `AppController::_enforcePermission` ya valida `refunds.can_delete`
     *    para esta acción (mapeo `deleteDocument` → `delete` en
     *    `_actionToPermission`).
     * 2. `_canOperateRefundStep` valida permiso de pipeline para el step
     *    actual; el servicio además valida la pertenencia del documento al
     *    refund (anti-IDOR).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_REFUNDS)]
    public function deleteDocument($refundId = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->Refunds->get($refundId);

        $gate = $this->_documentGate($record, 'eliminar');
        if ($gate !== null) {
            return $gate;
        }

        $deleted = $this->documentService->deleteDocument((int)$documentId, (int)$refundId);

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

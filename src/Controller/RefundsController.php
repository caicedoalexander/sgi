<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Constants\RoleConstants;
use App\Service\RefundService;
use Cake\I18n\Date;
use Cake\ORM\Query\SelectQuery;
use DateTimeInterface;

class RefundsController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private RefundService $refundService;

    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->refundService = $container->get(RefundService::class);
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
        $visibleStatuses = $this->refundService->getVisibleStatuses($roleName);

        $query = $this->Refunds->find()
            ->contain(['CreatedByUsers', 'BeneficiaryEmployees', 'BeneficiaryProviders', 'Invoices'])
            ->order(['Refunds.created' => 'DESC']);

        if (!empty($visibleStatuses)) {
            $query->where(['Refunds.status IN' => $visibleStatuses]);
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
        $query = $this->Refunds->find()
            ->contain(['CreatedByUsers', 'BeneficiaryEmployees', 'BeneficiaryProviders', 'Invoices'])
            ->order(['Refunds.created' => 'DESC']);

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
        $query = $this->Refunds->find()
            ->contain(['CreatedByUsers', 'BeneficiaryEmployees', 'BeneficiaryProviders', 'Invoices'])
            ->where(['Refunds.status !=' => RefundConstants::STATUS_PAGADO])
            ->order(['Refunds.created' => 'DESC']);

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
            $query->where(['Refunds.code LIKE' => '%' . $params['code'] . '%']);
        }
        if (!$skipStatus && !empty($params['status'])) {
            $query->where(['Refunds.status' => $params['status']]);
        }
        if (!empty($params['date_from'])) {
            $query->where(['Refunds.created >=' => $params['date_from']]);
        }
        if (!empty($params['date_to'])) {
            $query->where(['Refunds.created <=' => $params['date_to'] . ' 23:59:59']);
        }
    }

    public function view($id = null): void
    {
        $record = $this->Refunds->get($id, contain: [
            'CreatedByUsers',
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
        ]);

        $this->set(compact('record'));
    }

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
                'code' => !empty($data['code']) ? $data['code'] : null,
                'status' => RefundConstants::STATUS_AGRUPACION,
                'total_amount' => 0,
                'beneficiary_type' => $beneficiaryType ?: null,
                'beneficiary_employee_id' => $beneficiaryType === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE
                    ? $beneficiaryEmployeeId
                    : null,
                'beneficiary_provider_id' => $beneficiaryType === RefundConstants::BENEFICIARY_TYPE_PROVIDER
                    ? $beneficiaryProviderId
                    : null,
                'created_by' => $user->id,
            ]);

            if ($this->Refunds->save($record)) {
                $this->Flash->success('Reintegro creado exitosamente.');

                return $this->redirect(['action' => 'edit', $record->id]);
            }

            $this->Flash->error('No se pudo crear el registro. Intente de nuevo.');
        }

        [$employees, $providers] = $this->_loadBeneficiaryLists();
        $this->set(compact('record', 'employees', 'providers'));
    }

    private function _loadBeneficiaryLists(): array
    {
        $employeesTable = $this->fetchTable('Employees');
        $providersTable = $this->fetchTable('Providers');

        $employees = $employeesTable->find()
            ->select(['id', 'first_name', 'last_name1', 'last_name2'])
            ->order(['first_name' => 'ASC'])
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
            ->order(['name' => 'ASC'])
            ->toArray();

        return [$employees, $providers];
    }

    public function edit($id = null)
    {
        $record = $this->Refunds->get($id, contain: [
            'CreatedByUsers',
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
        ]);

        if ($this->request->is(['patch', 'post', 'put'])) {
            if (!$this->_ensureExpectedStatus($record->status)) {
                return $this->redirect(['action' => 'edit', $id]);
            }

            $data = $this->request->getData();
            $patchData = [];

            // Code: editable in all non-final states
            if (!$record->isPagado()) {
                $patchData['code'] = !empty($data['code']) ? $data['code'] : null;
            }

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
                $record = $this->Refunds->patchEntity($record, $patchData);
                $this->Refunds->save($record);
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
            $canAdvance = !$record->isPagado() && (RefundConstants::TRANSITIONS[$record->status] ?? null) !== null;
            $advanced = false;
            if ($canAdvance) {
                $result = $this->refundService->advanceStatus($record, $user->id);
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

            return $this->redirect(['action' => $advanced ? 'index' : 'edit', ...($advanced ? [] : [$id])]);
        }

        // Compute advance errors for the view (to decide button label)
        $nextStatus = RefundConstants::TRANSITIONS[$record->status] ?? null;
        $advanceErrors = [];
        if ($nextStatus) {
            $advanceErrors = $this->refundService->getTransitionErrors($record);
        }

        $groupFilters = $this->request->getQueryParams();
        $availableInvoices = $this->refundService->getAvailableInvoices($groupFilters)->all();
        $operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();
        [$employees, $providers] = $this->_loadBeneficiaryLists();

        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);
        $bankingEntities = $this->fetchTable('BankingEntities')->find('list')->toArray();
        $canRegisterPayment = in_array($roleName, [
            RoleConstants::TESORERIA, RoleConstants::ADMIN,
        ], true);
        $canAuthorizePayment = in_array($roleName, [
            RoleConstants::CONTADOR, RoleConstants::ADMIN,
        ], true);

        // Synthesize a pseudo-payment from record columns so the shared
        // payment_section element can render it. Reintegro stores a single
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

        $canRegress = $this->refundService->canRegress((int)$this->_getCurrentUser()->role_id, $roleName, $record->status);
        $previousStatus = $this->refundService->getPreviousStatus($record->status);
        $regressLockMessage = $this->refundService->getRegressionLockMessage($record);
        $pipelineLabels = RefundConstants::STATUS_LABELS;
        $currentStatus = $record->status;

        $this->set(compact(
            'record',
            'availableInvoices',
            'operationCenters',
            'employees',
            'providers',
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
        $record = $this->Refunds->get($id);

        if (!$this->_ensureExpectedStatus($record->status)) {
            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();

        $result = $this->refundService->advanceStatus($record, $user->id);

        if ($result['success']) {
            $nextLabel = RefundConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
            $this->Flash->success(sprintf('Registro avanzado a: %s', $nextLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result['error']);

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function regressStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($id);
        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);
        $reason = trim((string)$this->request->getData('reason', ''));

        $result = $this->refundService->regress(
            $record,
            (int)$user->role_id,
            $roleName,
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

    public function registerPayment($id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();

        // The shared payment_section JS posts 'amount'; map to 'payment_amount'.
        $data = $this->request->getData();
        if (!isset($data['payment_amount']) && isset($data['amount'])) {
            $data['payment_amount'] = $data['amount'];
        }

        $result = $this->refundService->registerPayment(
            (int)$id,
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
        $result = $this->refundService->authorizePayment((int)$id, $user->id);

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
        $result = $this->refundService->rejectPayment((int)$id, $user->id, $reason);

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
        $record = $this->Refunds->get($id);

        if (!$this->refundService->canDelete($record)) {
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

    public function addObservation($id = null)
    {
        $this->request->allowMethod(['post']);
        $user = $this->_getCurrentUser();

        $observationsTable = $this->fetchTable('RefundObservations');
        $observation = $observationsTable->newEntity([
            'refund_id' => $id,
            'user_id' => $user->id,
            'message' => $this->request->getData('message'),
        ]);

        if ($observationsTable->save($observation)) {
            $this->Flash->success('Observación agregada.');
        } else {
            $this->Flash->error('No se pudo agregar la observación.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }
}

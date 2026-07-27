<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Attribute\PipelineAction;
use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Service\AdvanceLegalizationApprovalGuard;
use App\Service\AdvanceLegalizationApprovalService;
use App\Service\AdvanceLegalizationDocumentService;
use App\Service\AdvanceLegalizationHistoryService;
use App\Service\AdvanceLegalizationService;
use App\Service\Approval\ApprovalUrlBuilder;
use App\Service\InvoicePipelineService;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\Service\Pipeline\Invoice\Policy\InvoiceFieldAccessPolicy;
use App\ValueObject\UserContext;
use App\ViewModel\AdvanceAddViewModel;
use App\ViewModel\AdvanceLegalizationViewModel;
use App\ViewModel\AdvanceViewViewModel;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;

class AdvancesController extends AppController
{
    use DocumentJsonPayloadTrait;

    /**
     * Lista blanca de campos mass-assignable al crear un Anticipo (audit CR-001):
     * bloquea approver_id, area_approval, payment_status, confirmed_by, accrued, advance_id.
     */
    private const ADVANCE_ALLOWED_FIELDS = [
        'provider_id', 'employee_id', 'operation_center_id',
        'expense_type_id', 'cost_center_id', 'amount', 'detail',
        'issue_date', 'due_date', 'document_type', 'registered_by',
        'pipeline_status', 'registration_date',
    ];

    private const ADVANCE_BLOCKED_FIELDS = [
        'approver_id', 'area_approval', 'payment_status',
        'confirmed_by', 'accrued', 'advance_id',
    ];

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private AdvanceLegalizationService $legalizationService;

    private AdvanceLegalizationDocumentService $documentService;

    private InvoicePipelineService $pipelineService;

    private AdvanceLegalizationActionPolicy $actionPolicy;

    private AdvanceLegalizationHistoryService $historyService;

    private AdvanceLegalizationApprovalService $approvalService;

    /**
     * Configura componentes y servicios del controlador.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->legalizationService = $this->getContainer()->get(AdvanceLegalizationService::class);
        $this->documentService = $this->getContainer()->get(AdvanceLegalizationDocumentService::class);
        $this->pipelineService = $this->getContainer()->get(InvoicePipelineService::class);
        $this->actionPolicy = $this->getContainer()->get(AdvanceLegalizationActionPolicy::class);
        $this->historyService = $this->getContainer()->get(AdvanceLegalizationHistoryService::class);
        $this->approvalService = $this->getContainer()->get(AdvanceLegalizationApprovalService::class);
        $this->fetchTable('Invoices');
    }

    /**
     * Obtiene el usuario autenticado de la sesión.
     *
     * @return object
     */
    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    /**
     * Obtiene la URL base de la aplicación.
     *
     * @return string
     */
    private function _getBaseUrl(): string
    {
        return ApprovalUrlBuilder::baseFromRequest($this->request);
    }

    /**
     * Parse a Colombian-formatted amount string ("1.234,56") into a float.
     * Empty string or invalid input returns 0.0.
     */
    private function _parseCop(string $raw): float
    {
        $normalized = str_replace('.', '', $raw);
        $normalized = str_replace(',', '.', $normalized);

        return (float)$normalized;
    }

    /**
     * Payload de causación del paso Contabilidad: checkbox + fecha + lista para
     * pago. Compartido por las 3 salidas del paso (markExact, registerShortage,
     * registerSurplus). El gate lo aplica ContabilidadState vía el service.
     *
     * @return array{accrued: bool, accrual_date: string|null, ready_for_payment: string|null}
     */
    private function _accountingPayload(): array
    {
        $date = trim((string)$this->request->getData('accrual_date', ''));
        $ready = trim((string)$this->request->getData('ready_for_payment', ''));

        return [
            'accrued' => (bool)$this->request->getData('accrued'),
            'accrual_date' => $date !== '' ? $date : null,
            'ready_for_payment' => $ready !== '' ? $ready : null,
        ];
    }

    /**
     * Reject the action when the current role cannot perform it on the leg's
     * current state. Caller does `return $this->_denyAction(...)`.
     */
    private function _denyAction(int $advanceId): Response
    {
        $this->Flash->error('No tienes permiso para esta acción en el estado actual.');

        return $this->redirect(['action' => 'legalization', $advanceId]);
    }

    /**
     * Array de URL destino tras una transición exitosa. Fuente única para el
     * redirect HTTP (`_redirectAfterTransition`) y para el campo `redirect` de
     * las respuestas JSON: si el destino de un cierre cambia, cambia en ambos.
     *
     * El service muta `$leg->status` in-place sobre la instancia recibida
     * (`AdvanceLegalizationService::_setStatus()`, y `registerRefundPayment()` que
     * asigna directo), así que la entidad ya refleja el estado nuevo cuando se
     * llama a este helper. Cerrar la legalización lleva al hub de consulta (patrón
     * de `RefundsController::confirmPayment`); mover de paso devuelve a la bandeja.
     *
     * @return array<string, mixed>|array<int, mixed>
     */
    private function _afterTransitionUrl(AdvanceLegalization $leg, int $advanceId): array
    {
        return $leg->isLegalized()
            ? ['action' => 'view', $advanceId]
            : ['action' => 'pendingLegalization'];
    }

    /**
     * Redirect HTTP tras una transición exitosa; delega el destino a
     * `_afterTransitionUrl`.
     *
     * SOLO se invoca en el camino de éxito. En fallo, cada acción conserva su
     * redirect a `legalization/{id}` para que el usuario lea el flash sin perder
     * el contexto: una transición fallida deja `$leg->status` sin cambiar, y este
     * helper lo mandaría a la bandeja por error.
     */
    private function _redirectAfterTransition(AdvanceLegalization $leg, int $advanceId): Response
    {
        return $this->redirect($this->_afterTransitionUrl($leg, $advanceId));
    }

    /**
     * "Mis Anticipos" — filtra por los pipeline_status visibles del rol.
     */
    #[Permission(action: 'view')]
    public function index(): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $visibleStatuses = $this->pipelineService->getVisibleStatuses($roleId);

        $query = $invoicesTable->find()
            ->where(['Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->contain([
                'Providers',
                'Employees',
                'OperationCenters',
                'AdvanceLegalization',
            ])
            ->orderBy(['Invoices.created' => 'DESC']);

        $query->where($this->_visibleStatusConditions('Invoices.pipeline_status', $visibleStatuses));

        $pipelineStatus = (string)$this->request->getQuery('pipeline_status', '');
        if ($pipelineStatus !== '') {
            $query->where(['Invoices.pipeline_status' => $pipelineStatus]);
        }

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['Invoices.invoice_number LIKE' => '%' . $search . '%']);
        }

        $advances = $this->paginate($query);

        $this->set(compact('advances', 'visibleStatuses'));
    }

    /**
     * "Todos los Anticipos" — sin filtros de rol.
     */
    #[Permission(action: 'view')]
    public function all(): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $query = $invoicesTable->find()
            ->where(['Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->contain([
                'Providers',
                'Employees',
                'OperationCenters',
                'AdvanceLegalization',
            ])
            ->orderBy(['Invoices.created' => 'DESC']);

        $pipelineStatus = (string)$this->request->getQuery('pipeline_status', '');
        if ($pipelineStatus !== '') {
            $query->where(['Invoices.pipeline_status' => $pipelineStatus]);
        }

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['Invoices.invoice_number LIKE' => '%' . $search . '%']);
        }

        $advances = $this->paginate($query);
        $visibleStatuses = [];
        $this->set(compact('advances', 'visibleStatuses'));
        $this->render('index');
    }

    /**
     * "Pendientes de Legalización" — anticipos pagados con legalización en curso.
     */
    #[Permission(action: 'view')]
    public function pendingLegalization(): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        // Pasos del pipeline `legalizations` operables por el rol — filtra la
        // bandeja como en los otros 5 módulos. Nombre distinto de la variable
        // `$visibleStatuses` de vista (abajo) para no confundir ambos usos.
        $operableSteps = $this->actionPolicy->getVisibleStatuses(
            (int)$this->_getCurrentUser()->role_id,
        );

        // `_visibleStatusConditions` devuelve `1 = 0` cuando el rol no opera
        // ningún paso, así que la bandeja sale vacía sin caso especial.
        $stepConditions = $this->_visibleStatusConditions(
            'AdvanceLegalization.status',
            $operableSteps,
        );

        // innerJoinWith filtra a anticipos que tengan legalización en curso sin
        // duplicar el JOIN al hacer el contain (audit MA-009). matching() habría
        // hidratado además el alias _matchingData en cada fila.
        $query = $invoicesTable->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            ])
            ->innerJoinWith('AdvanceLegalization', function ($q) use ($stepConditions) {
                // El `!= legalizada` es redundante — `legalizada` no es un step
                // operable — pero se conserva como defensa en profundidad.
                return $q->where($stepConditions)->where([
                    'AdvanceLegalization.status !=' => AdvanceConstants::STATUS_LEGALIZADA,
                ]);
            })
            ->contain([
                'Providers',
                'Employees',
                'OperationCenters',
                'AdvanceLegalization',
            ])
            ->orderBy(['Invoices.created' => 'DESC']);

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['Invoices.invoice_number LIKE' => '%' . $search . '%']);
        }

        $advances = $this->paginate($query);
        // `$visibleStatuses` NO alimenta nada en el template compartido
        // index.php: allí solo aparece en el `@var` del docblock, nunca se
        // dereferencia (los chips son un array estático hardcodeado). Se
        // conserva `[]` para cumplir ese contrato `@var` y espejar a `all()`.
        $visibleStatuses = [];
        $this->set(compact('advances', 'visibleStatuses'));
        $this->render('index');
    }

    /**
     * Crea un anticipo.
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add(): ?Response
    {
        /** @var \App\Model\Table\InvoicesTable $invoicesTable */
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $dropdowns = $this->_dropdowns();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $data['document_type'] = InvoiceConstants::DOCTYPE_ANTICIPO;
            $data['registered_by'] = (int)$this->_getCurrentUser()->id;
            $data['pipeline_status'] = InvoiceConstants::STATUS_APROBACION;
            $data['registration_date'] = date('Y-m-d');
            // Anticipos no tienen fecha de vencimiento; usamos la de emisión.
            if (empty($data['due_date']) && !empty($data['issue_date'])) {
                $data['due_date'] = $data['issue_date'];
            }

            if (empty($data['provider_id']) && empty($data['employee_id'])) {
                $this->Flash->error('Debe seleccionar un proveedor o un empleado como beneficiario.');
                $vm = new AdvanceAddViewModel($invoicesTable->newEmptyEntity(), $dropdowns);
                $this->set('invoice', $vm->invoice);
                $this->set($vm->dropdowns);

                return null;
            }

            // Lista blanca de mass-assignment (audit CR-001).
            $accessibleFields = array_fill_keys(self::ADVANCE_ALLOWED_FIELDS, true)
                + array_fill_keys(self::ADVANCE_BLOCKED_FIELDS, false);
            $invoice = $invoicesTable->patchEntity(
                $invoicesTable->newEmptyEntity(),
                $data,
                ['accessibleFields' => $accessibleFields],
            );
            $vm = new AdvanceAddViewModel($invoice, $dropdowns);

            if ($invoicesTable->save($vm->invoice)) {
                $this->Flash->success('Anticipo creado.');

                return $this->redirect(['action' => 'view', $vm->invoice->id]);
            }

            $this->Flash->error('No se pudo guardar el anticipo.');
            $this->set('invoice', $vm->invoice);
            $this->set($vm->dropdowns);

            return null;
        }

        $vm = new AdvanceAddViewModel($invoicesTable->newEmptyEntity(), $dropdowns);
        $this->set('invoice', $vm->invoice);
        $this->set($vm->dropdowns);

        return null;
    }

    /**
     * Muestra un anticipo.
     *
     * @param int|null $id ID del anticipo.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'view')]
    public function view(?int $id = null): ?Response
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoicesTable->get($id, contain: [
            'Providers',
            'Employees',
            'OperationCenters',
            'ExpenseTypes',
            'CostCenters',
            'RegisteredByUsers',
            'InvoiceDocuments' => ['UploadedByUsers'],
            'InvoicePayments' => ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
            'AdvanceLegalization' => [
                'AdvanceLegalizationSignatures' => ['SignedByUsers'],
                'AdvanceLegalizationDocuments' => ['UploadedByUsers'],
            ],
        ]);

        if ($invoice->document_type !== InvoiceConstants::DOCTYPE_ANTICIPO) {
            $this->Flash->error('Esta factura no es un Anticipo.');

            return $this->redirect(['action' => 'index']);
        }

        $leg = $invoice->advance_legalization ?? null;
        $linkedInvoices = [];
        if ($leg) {
            $linkedInvoices = $invoicesTable->find()
                ->where([
                    'Invoices.document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                    'Invoices.advance_id' => $invoice->id,
                ])
                ->contain(['Providers', 'Employees', 'InvoiceDocuments'])
                ->orderBy(['Invoices.issue_date' => 'ASC'])
                ->all();
        }

        $this->set('viewModel', new AdvanceViewViewModel($invoice, $leg, $linkedInvoices));

        return null;
    }

    /**
     * Vista dedicada del proceso de legalización del anticipo (Phase 2).
     */
    #[Permission(action: 'edit')]
    public function legalization(?int $id = null): ?Response
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoicesTable->get($id, contain: [
            'Providers',
            'Employees',
            'OperationCenters',
            'ExpenseTypes',
            'CostCenters',
            'RegisteredByUsers',
            'InvoiceObservations' => ['Users'],
            'AdvanceLegalization' => [
                'AdvanceLegalizationSignatures' => ['SignedByUsers'],
                'AdvanceLegalizationDocuments' => ['UploadedByUsers'],
            ],
        ]);

        if ($invoice->document_type !== InvoiceConstants::DOCTYPE_ANTICIPO) {
            $this->Flash->error('Esta factura no es un Anticipo.');

            return $this->redirect(['action' => 'index']);
        }

        $leg = $invoice->advance_legalization ?? null;
        if (!$leg) {
            // Branch defensivo: cubre acceso directo por URL a /advances/legalization/{id}
            // cuando el anticipo todavía no ha sido pagado y por tanto el subscriber
            // LegalizationInitializerSubscriber aún no ha creado la fila en
            // advance_legalizations. En el flujo normal, view() redirige aquí solo
            // cuando la legalización ya existe (audit MA-007).
            $this->Flash->info('La legalización aún no ha iniciado. Espere a que el anticipo esté en estado Pagada.');

            return $this->redirect(['action' => 'view', $invoice->id]);
        }

        $this->set('viewModel', $this->_buildLegalizationViewModel(
            $invoice,
            $leg,
            $this->_getCurrentUser(),
        ));

        return null;
    }

    /**
     * Fábrica del ViewModel de la vista de legalización. Espejo de
     * `RefundsController::_buildEditViewModel()`: concentra las 14 llamadas al
     * policy y la carga de datos crudos para mantener la action delgada.
     */
    private function _buildLegalizationViewModel(
        Invoice $invoice,
        AdvanceLegalization $leg,
        object $user,
    ): AdvanceLegalizationViewModel {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $roleId = (int)$user->role_id;
        $isAprobacion = $leg->status === AdvanceConstants::STATUS_APROBACION;

        // Datos crudos: el VM solo deriva, no consulta (audit CR-102).
        $linkedInvoices = $invoicesTable->find()
            ->where([
                'Invoices.document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                'Invoices.advance_id' => $invoice->id,
            ])
            ->contain(['Providers', 'Employees', 'InvoiceDocuments'])
            ->orderBy(['Invoices.issue_date' => 'ASC'])
            ->all();

        $bankingEntities = TableRegistry::getTableLocator()->get('BankingEntities')
            ->find('list')
            ->all()
            ->toArray();

        $surplusPayment = null;
        if ($leg->surplus_payment_id) {
            $surplusPayment = TableRegistry::getTableLocator()->get('InvoicePayments')->get(
                $leg->surplus_payment_id,
                contain: ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
            );
        }

        // Gate para las acciones inline sobre las hijas (DIAN + soporte). Las
        // facturas hijas del anticipo viven en `aprobacion` hasta
        // moveToRevisionFirmas, así que el gate se resuelve contra ese step del
        // pipeline de facturas (espejo de RefundsController::view). El servidor
        // sigue siendo la autoridad al recibir el update/upload; esto solo PINTA.
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

        return new AdvanceLegalizationViewModel(
            invoice: $invoice,
            leg: $leg,
            roleName: $user->role->name ?? '',
            linkedInvoices: $linkedInvoices,
            bankingEntities: $bankingEntities,
            surplusPayment: $surplusPayment,
            canRegisterRefund: $this->actionPolicy->canRegisterRefund($leg, $roleId),
            canAuthorizeRefundPayment: $this->actionPolicy->canAuthorizeRefundPayment($leg, $roleId),
            canConfirmRefundPayment: $this->actionPolicy->canConfirmRefundPayment($leg, $roleId),
            approvals: $isAprobacion
                ? $this->approvalService->getCurrentApprovals((int)$leg->id) : [],
            approvalSummary: $isAprobacion
                ? $this->approvalService->getApprovalSummary((int)$leg->id)
                : ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0],
            canManageApprovers: $this->actionPolicy->canConsolidateApproval($leg, $roleId),
            approvers: $isAprobacion
                ? $this->fetchTable('Users')->find('list', keyField: 'id', valueField: 'full_name')
                    ->where(['active' => true])->toArray()
                : [],
            canOperateCurrentStep: $this->actionPolicy->canOperateCurrentStep($leg, $roleId),
            canLinkInvoices: $this->actionPolicy->canLinkInvoices($leg, $roleId),
            canUploadRelationDocument: $this->actionPolicy->canUploadRelationDocument($leg, $roleId),
            canMoveToAprobacion: $this->actionPolicy->canMoveToAprobacion($leg, $roleId),
            canMarkSigned: $this->actionPolicy->canMarkSigned($leg, $roleId),
            canReturnToAprobacion: $this->actionPolicy->canReturnToAprobacion($leg, $roleId),
            canMarkExact: $this->actionPolicy->canMarkExact($leg, $roleId),
            canRegisterShortage: $this->actionPolicy->canRegisterShortage($leg, $roleId),
            canRegisterSurplus: $this->actionPolicy->canRegisterSurplus($leg, $roleId),
            canConfirmShortage: $this->actionPolicy->canConfirmShortage($leg, $roleId),
            canManageDocuments: $this->actionPolicy->canManageDocuments($leg, $roleId),
            childReadiness: (new AdvanceLegalizationApprovalGuard())->childRequirements((int)$invoice->id),
            canResolveDianChildren: $canResolveDian,
            canUploadChildSupport: $canOperateAprobacion && $canEditInvoices,
        );
    }

    /**
     * The Anticipo is an Invoice; edit lives in InvoicesController.
     */
    #[Permission(action: 'edit')]
    public function edit(?int $id = null): Response
    {
        return $this->redirect(['controller' => 'Invoices', 'action' => 'edit', $id]);
    }

    /**
     * AJAX endpoint que devuelve el fragment HTML del modal "Vincular facturas".
     *
     * Audit SU-003 — el modal precargaba todas las facturas Legalización del OC en
     * cada render de `legalization()`. Ahora la query corre solo cuando se abre el
     * modal (o se aplica un filtro). Acepta filtros vía query string: date_from,
     * date_to, provider_id, operation_center_id (default: OC del anticipo).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function linkCandidates(?int $id = null): ?Response
    {
        $this->request->allowMethod(['get']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canLinkInvoices($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $advance = $invoicesTable->get($leg->advance_invoice_id, fields: ['id', 'operation_center_id']);

        // `??` (no `?:`): getQuery devuelve null si el parámetro no vino (modal recién
        // abierto → default al OC del anticipo) y '' si el usuario eligió "Todos"
        // (→ '' se conserva y no aplica filtro de OC). `?:` trataba '' como ausente.
        $filters = [
            'date_from' => $this->request->getQuery('date_from') ?: '',
            'date_to' => $this->request->getQuery('date_to') ?: '',
            'provider_id' => $this->request->getQuery('provider_id') ?: '',
            'operation_center_id' => $this->request->getQuery('operation_center_id')
                ?? ($advance->operation_center_id ?? ''),
        ];

        $conditions = [
            'Invoices.advance_id IS' => null,
            'Invoices.petty_cash_record_id IS' => null,
            'Invoices.pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'Invoices.document_type IN' => [
                InvoiceConstants::DOCTYPE_LEGALIZACION,
                InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            ],
        ];
        if (!empty($filters['operation_center_id'])) {
            $conditions['Invoices.operation_center_id'] = (int)$filters['operation_center_id'];
        }
        if (!empty($filters['date_from'])) {
            $conditions['Invoices.issue_date >='] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $conditions['Invoices.issue_date <='] = $filters['date_to'];
        }
        if (!empty($filters['provider_id'])) {
            $conditions['Invoices.provider_id'] = (int)$filters['provider_id'];
        }

        // Límite duro como red de seguridad — si el set crece, se añade paginación
        // sin romper la API. 200 cubre el caso operativo realista por OC.
        $candidates = $invoicesTable->find()
            ->where($conditions)
            ->contain(['Providers', 'Employees', 'OperationCenters'])
            ->orderBy(['Invoices.issue_date' => 'DESC'])
            ->limit(200)
            ->all();

        $providers = TableRegistry::getTableLocator()->get('Providers')
            ->find('list', keyField: 'id', valueField: 'name')
            ->where(['active' => true])
            ->orderBy(['name' => 'ASC'])
            ->toArray();
        $operationCenters = TableRegistry::getTableLocator()->get('OperationCenters')
            ->find('list', keyField: 'id', valueField: 'name')
            ->orderBy(['name' => 'ASC'])
            ->toArray();

        $this->set(compact('leg', 'candidates', 'filters', 'providers', 'operationCenters'));
        $this->viewBuilder()->disableAutoLayout();

        return null;
    }

    /**
     * Bulk-link Legalización invoices to this advance (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function linkInvoices(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canLinkInvoices($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        $userId = (int)$this->_getCurrentUser()->id;

        $invoiceIds = (array)$this->request->getData('invoice_ids', []);
        $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));

        $result = $this->legalizationService->linkInvoices($leg, $invoiceIds, $userId);
        if ($result->success) {
            $linked = (int)($result->data['linked'] ?? 0);
            $requested = count($invoiceIds);
            if ($linked < $requested) {
                $this->Flash->warning(sprintf(
                    '%d de %d factura(s) vinculada(s); el resto ya no estaba disponible.',
                    $linked,
                    $requested,
                ));
            } else {
                $this->Flash->success($linked . ' factura(s) vinculada(s).');
            }
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al vincular.');
        }

        return $this->redirect(['action' => 'legalization', $id]);
    }

    /**
     * Unlink a single Legalización invoice (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function unlinkInvoice(?int $id = null, ?int $invoiceId = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canUnlinkInvoice($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->unlinkInvoice($leg, (int)$invoiceId, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->Flash->success('Factura desvinculada.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al desvincular.');
        }

        return $this->redirect(['action' => 'legalization', $id]);
    }

    /**
     * Upload the relation-of-invoices document (POST multipart).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function uploadRelationDocument(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canUploadRelationDocument($leg, (int)$this->_getCurrentUser()->role_id)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => 'No tienes permiso para esta acción en el estado actual.',
                ]);
            }

            return $this->_denyAction((int)$id);
        }
        $file = $this->request->getUploadedFile('relation_document');
        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'Adjunte un archivo PDF de relación de facturas.']);
            }
            $this->Flash->error('Adjunte un archivo PDF de relación de facturas.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $result = $this->documentService->attachRelationDocument($leg, $file, (int)$this->_getCurrentUser()->id);

        if ($result->success) {
            $this->historyService->recordFieldChange(
                (int)$leg->id,
                'document',
                null,
                $result->data->file_name,
                (int)$this->_getCurrentUser()->id,
            );
        }

        if ($this->_isJsonRequest()) {
            if (!$result->success) {
                return $this->_jsonResponse(['success' => false, 'error' => $result->firstError() ?? 'Error al adjuntar.']);
            }

            return $this->_jsonResponse(['success' => true]);
        }

        if ($result->success) {
            $this->Flash->success('Documento adjuntado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al adjuntar.');
        }

        return $this->redirect(['action' => 'legalization', $id]);
    }

    /**
     * Sube un soporte general de la legalización (POST multipart, name="file").
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function uploadLegalizationDocument(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canManageDocuments($leg, (int)$this->_getCurrentUser()->role_id)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => 'No tienes permiso para esta acción en el estado actual.',
                ], 403);
            }

            return $this->_denyAction((int)$id);
        }

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            $msg = 'No se recibió ningún archivo válido.';
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => $msg], 400);
            }
            $this->Flash->error($msg);

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $result = $this->documentService->uploadDocument(
            (int)$leg->id,
            $file,
            (int)$this->_getCurrentUser()->id,
        );

        if (!is_string($result)) {
            $this->historyService->recordFieldChange(
                (int)$leg->id,
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

            $canDelete = $leg->canManageDocuments();
            $deleteUrl = $canDelete
                ? Router::url(['action' => 'deleteLegalizationDocument', $leg->advance_invoice_id, $result->id])
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

        return $this->redirect(['action' => 'legalization', $id]);
    }

    /**
     * Elimina un soporte general de la legalización (POST/DELETE).
     * Anti-IDOR: el service filtra por id + legalization_id.
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function deleteLegalizationDocument(?int $id = null, ?int $documentId = null): Response
    {
        $this->request->allowMethod(['post', 'delete']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canManageDocuments($leg, (int)$this->_getCurrentUser()->role_id)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => 'No tienes permiso para esta acción en el estado actual.',
                ], 403);
            }

            return $this->_denyAction((int)$id);
        }

        $documentsTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationDocuments');
        $document = $documentsTable->find()
            ->where(['id' => $documentId, 'legalization_id' => $leg->id])
            ->first();
        $fileName = $document?->file_name;

        $deleted = $this->documentService->deleteDocument((int)$documentId, (int)$leg->id);

        if ($deleted) {
            $this->historyService->recordFieldChange(
                (int)$leg->id,
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

            return $this->_jsonResponse(['success' => false, 'error' => 'No se pudo eliminar el soporte.'], 404);
        }

        if ($deleted) {
            $this->Flash->success('El soporte ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'legalization', $id]);
    }

    /**
     * Move legalization from validacion → aprobacion (arma el grupo) (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function moveToAprobacion(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canMoveToAprobacion($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->moveToAprobacion($leg, (int)$this->_getCurrentUser()->id);
        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al avanzar.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $this->Flash->success('Legalización enviada a Aprobación de área.');

        return $this->_redirectAfterTransition($leg, (int)$id);
    }

    /**
     * Consolidate legalization from aprobacion → revision_firmas (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function moveToRevision(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canConsolidateApproval($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->moveToRevisionFirmas($leg, (int)$this->_getCurrentUser()->id);
        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al avanzar.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $this->Flash->success('Aprobación consolidada. Legalización enviada a Revisión y Firmas.');

        return $this->_redirectAfterTransition($leg, (int)$id);
    }

    /**
     * Regresa la legalización de Aprobación a Validación para editar el grupo,
     * invalidando las aprobaciones activas (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function returnFromAprobacion(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canReturnFromAprobacion($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->returnToValidacionFromAprobacion($leg, (int)$this->_getCurrentUser()->id);
        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al regresar.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $this->approvalService->supersedeAll((int)$leg->id); // invalida aprobaciones activas
        $this->Flash->success('Legalización regresada a Validación. Los enlaces de aprobación fueron invalidados.');

        return $this->_redirectAfterTransition($leg, (int)$id);
    }

    /**
     * Envía enlaces de aprobación de área a los aprobadores seleccionados (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function sendApprovalLinks(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $user = $this->_getCurrentUser();
        if (!$this->actionPolicy->canConsolidateApproval($leg, (int)$user->role_id)) {
            return $this->_denyAction((int)$id);
        }

        $result = $this->approvalService->sendApprovalLinks(
            $leg,
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

        return $this->redirect(['action' => 'legalization', $id]);
    }

    /**
     * Modifica el grupo de aprobadores de área, invalidando y reenviando enlaces (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function modifyApprovers(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $user = $this->_getCurrentUser();
        if (!$this->actionPolicy->canConsolidateApproval($leg, (int)$user->role_id)) {
            return $this->_denyAction((int)$id);
        }

        $result = $this->approvalService->modifyApprovers(
            $leg,
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

        return $this->redirect(['action' => 'legalization', $id]);
    }

    /**
     * Mark relation document as signed and advance to contabilidad (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS, step: AdvanceConstants::STATUS_REVISION_FIRMAS)]
    public function markSigned(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canMarkSigned($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->markSigned($leg, (int)$this->_getCurrentUser()->id);
        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al firmar.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $this->Flash->success('Documento marcado como firmado.');

        return $this->_redirectAfterTransition($leg, (int)$id);
    }

    /**
     * Reject signature and bounce back to aprobacion (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS, step: AdvanceConstants::STATUS_REVISION_FIRMAS)]
    public function returnToAprobacion(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canReturnToAprobacion($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        $reason = (string)$this->request->getData('reason', '');
        $result = $this->legalizationService->returnToAprobacion($leg, $reason, (int)$this->_getCurrentUser()->id);
        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al devolver.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $this->Flash->success('Legalización devuelta a Aprobación.');

        return $this->_redirectAfterTransition($leg, (int)$id);
    }

    /**
     * Close legalization as caso exacto (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function markExact(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canMarkExact($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        if (!$this->_ensureExpectedStatus($leg->status)) {
            return $this->redirect(['action' => 'legalization', $id]);
        }
        $result = $this->legalizationService->markExact(
            $leg,
            $this->_accountingPayload(),
            (int)$this->_getCurrentUser()->id,
        );
        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al legalizar.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $this->Flash->success('Anticipo legalizado (caso exacto).');

        return $this->_redirectAfterTransition($leg, (int)$id);
    }

    /**
     * Contabilidad declares a shortage and pushes legalization to Tesorería (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function registerShortage(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canRegisterShortage($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        if (!$this->_ensureExpectedStatus($leg->status)) {
            return $this->redirect(['action' => 'legalization', $id]);
        }
        $amount = $this->_parseCop((string)$this->request->getData('shortage_amount'));
        $result = $this->legalizationService->registerShortage(
            $leg,
            $amount,
            $this->_accountingPayload(),
            (int)$this->_getCurrentUser()->id,
        );
        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al registrar faltante.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $this->Flash->success('Faltante registrado. La legalización pasó a Tesorería.');

        return $this->_redirectAfterTransition($leg, (int)$id);
    }

    /**
     * Tesorería confirms beneficiary's shortage deposit (POST multipart).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function confirmShortage(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canConfirmShortage($leg, (int)$this->_getCurrentUser()->role_id)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => 'No tienes permiso para esta acción en el estado actual.',
                ]);
            }

            return $this->_denyAction((int)$id);
        }
        $data = $this->request->getData();
        $result = $this->legalizationService->confirmShortageReceipt(
            $leg,
            $data,
            (int)$this->_getCurrentUser()->id,
        );

        if ($this->_isJsonRequest()) {
            if ($result->success) {
                return $this->_jsonResponse([
                    'success' => true,
                    'redirect' => Router::url($this->_afterTransitionUrl($leg, (int)$id)),
                ]);
            }

            return $this->_jsonResponse(['success' => false, 'error' => $result->firstError() ?? 'Error al confirmar consignación.']);
        }

        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al confirmar consignación.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $this->Flash->success('Consignación confirmada. Anticipo legalizado.');

        return $this->_redirectAfterTransition($leg, (int)$id);
    }

    /**
     * Contabilidad declares a surplus and pushes legalization to Tesorería (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function registerSurplus(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canRegisterSurplus($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        if (!$this->_ensureExpectedStatus($leg->status)) {
            return $this->redirect(['action' => 'legalization', $id]);
        }
        $amount = $this->_parseCop((string)$this->request->getData('surplus_amount'));
        $result = $this->legalizationService->registerSurplus(
            $leg,
            $amount,
            $this->_accountingPayload(),
            (int)$this->_getCurrentUser()->id,
        );
        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al registrar sobrante.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $this->Flash->success('Sobrante registrado. La legalización pasó a Tesorería.');

        return $this->_redirectAfterTransition($leg, (int)$id);
    }

    /**
     * Tesorería registers a refund payment to the beneficiary (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function registerRefund(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canRegisterRefund($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        $data = $this->request->getData();
        $result = $this->legalizationService->registerRefundPayment(
            $leg,
            $data,
            (int)$this->_getCurrentUser()->id,
        );
        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al registrar reintegro.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $this->Flash->success('Reintegro registrado. Pendiente de autorización por el Contador.');

        return $this->_redirectAfterTransition($leg, (int)$id);
    }

    /**
     * Resolve the AdvanceLegalization tied to a given Anticipo invoice id.
     *
     * Scope: by design, all users with `advances.edit` see all advances regardless
     * of operation_center_id. Action-level authorization (rol×state) is enforced
     * via AdvanceLegalizationActionPolicy before any mutating operation. See audit
     * 2026-05-05 (MA-002) for the rationale.
     *
     * Returns null when no legalization exists for the given anticipo. The caller
     * uses _redirectMissing() to flash + redirect (audit MI-007), evitando un 404
     * genérico sin contexto.
     */
    private function _loadLegalization(int $advanceInvoiceId): ?AdvanceLegalization
    {
        return TableRegistry::getTableLocator()
            ->get('AdvanceLegalizations')
            ->find()
            ->where(['advance_invoice_id' => $advanceInvoiceId])
            ->first();
    }

    /**
     * Flash de error + redirect a la lista de anticipos cuando _loadLegalization
     * devuelve null.
     */
    private function _redirectMissing(): Response
    {
        $this->Flash->error('Legalización no encontrada.');

        return $this->redirect(['action' => 'index']);
    }

    /**
     * @return array<string, mixed>
     */
    private function _dropdowns(): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        return [
            'providers' => $invoicesTable->Providers->find('list')->orderBy(['Providers.name' => 'ASC'])->all(),
            'operationCenters' => $invoicesTable->OperationCenters->find('codeList')->all(),
            'expenseTypes' => $invoicesTable->ExpenseTypes->find('list')->all(),
            'costCenters' => $invoicesTable->CostCenters->find('codeList')->all(),
            'employees' => $this->fetchTable('Employees')
                ->find('list', keyField: 'id', valueField: 'full_name')
                ->orderBy(['Employees.first_name' => 'ASC', 'Employees.last_name1' => 'ASC'])
                ->toArray(),
        ];
    }
}

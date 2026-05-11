<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Model\Entity\AdvanceLegalization;
use App\Service\AdvanceLegalizationDocumentService;
use App\Service\AdvanceLegalizationService;
use App\Service\InvoicePipelineService;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\ViewModel\AdvanceAddViewModel;
use App\ViewModel\AdvanceLegalizationViewModel;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;

class AdvancesController extends AppController
{
    use DocumentJsonPayloadTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Acciones del flujo de legalización autorizadas por `pipeline_permissions`
     * (rol×paso) vía `AdvanceLegalizationActionPolicy`. El gate CRUD del módulo
     * `advances` no aplica a estas acciones — el chequeo fino lo hace cada
     * endpoint contra el policy correspondiente al paso actual.
     *
     * @var array<int, string>
     */
    protected array $pipelineActions = [
        'linkCandidates',
        'linkInvoices',
        'unlinkInvoice',
        'uploadRelationDocument',
        'moveToRevision',
        'markSigned',
        'returnToValidacion',
        'markExact',
        'registerShortage',
        'confirmShortage',
        'registerSurplus',
        'registerRefund',
        'confirmRefundPayment',
    ];

    private AdvanceLegalizationService $legalizationService;

    private AdvanceLegalizationDocumentService $documentService;

    private InvoicePipelineService $pipelineService;

    private AdvanceLegalizationActionPolicy $actionPolicy;

    public function initialize(): void
    {
        parent::initialize();
        $this->legalizationService = $this->getContainer()->get(AdvanceLegalizationService::class);
        $this->documentService = $this->getContainer()->get(AdvanceLegalizationDocumentService::class);
        $this->pipelineService = $this->getContainer()->get(InvoicePipelineService::class);
        $this->actionPolicy = $this->getContainer()->get(AdvanceLegalizationActionPolicy::class);
        $this->fetchTable('Invoices');
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
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
     * Reject the action when the current role cannot perform it on the leg's
     * current state. Caller does `return $this->_denyAction(...)`.
     */
    private function _denyAction(int $advanceId): Response
    {
        $this->Flash->error('No tienes permiso para esta acción en el estado actual.');

        return $this->redirect(['action' => 'view', $advanceId]);
    }

    /**
     * "Mis Anticipos" — filtra por los pipeline_status visibles del rol.
     */
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

        $advances = $this->paginate($query);

        $this->set(compact('advances', 'visibleStatuses'));
    }

    /**
     * "Todos los Anticipos" — sin filtros de rol.
     */
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

        $advances = $this->paginate($query);
        $visibleStatuses = [];
        $this->set(compact('advances', 'visibleStatuses'));
        $this->render('index');
    }

    /**
     * "Pendientes de Legalización" — anticipos pagados con legalización en curso.
     */
    public function pendingLegalization(): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        // innerJoinWith filtra a anticipos que tengan legalización en curso sin
        // duplicar el JOIN al hacer el contain (audit MA-009). matching() habría
        // hidratado además el alias _matchingData en cada fila.
        $query = $invoicesTable->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            ])
            ->innerJoinWith('AdvanceLegalization', function ($q) {
                return $q->where([
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

        $advances = $this->paginate($query);
        $visibleStatuses = [];
        $this->set(compact('advances', 'visibleStatuses'));
        $this->render('index');
    }

    public function add(): ?Response
    {
        /** @var \App\Model\Table\InvoicesTable $invoicesTable */
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $dropdowns = $this->_dropdowns();

        if ($this->request->is('post')) {
            $vm = AdvanceAddViewModel::fromRequest(
                $invoicesTable,
                $this->request->getData(),
                (int)$this->_getCurrentUser()->id,
                $dropdowns,
            );

            if (!empty($vm->errors)) {
                $this->Flash->error($vm->errors[0]);
                $this->set('invoice', $vm->invoice);
                $this->set($vm->dropdowns);

                return null;
            }

            if ($invoicesTable->save($vm->invoice)) {
                $this->Flash->success('Anticipo creado.');

                return $this->redirect(['action' => 'view', $vm->invoice->id]);
            }

            $this->Flash->error('No se pudo guardar el anticipo.');
            $this->set('invoice', $vm->invoice);
            $this->set($vm->dropdowns);

            return null;
        }

        $vm = AdvanceAddViewModel::forForm($invoicesTable, $dropdowns);
        $this->set('invoice', $vm->invoice);
        $this->set($vm->dropdowns);

        return null;
    }

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
            'AdvanceLegalization',
        ]);

        if ($invoice->document_type !== InvoiceConstants::DOCTYPE_ANTICIPO) {
            $this->Flash->error('Esta factura no es un Anticipo.');

            return $this->redirect(['action' => 'index']);
        }

        // Cuando ya hay legalización iniciada, redirigir a la vista dedicada.
        if ($invoice->advance_legalization) {
            return $this->redirect(['action' => 'legalization', $invoice->id]);
        }

        $this->set(compact('invoice'));
        $this->set('linkedInvoices', []);
        $this->set('linkedTotal', 0.0);

        return null;
    }

    /**
     * Vista dedicada del proceso de legalización del anticipo (Phase 2).
     */
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
            'AdvanceLegalization' => ['AdvanceLegalizationSignatures' => ['SignedByUsers']],
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

        $user = $this->_getCurrentUser();
        $roleName = $user->role->name ?? '';

        // Cargar datos crudos que el VM solo deriva (audit CR-102).
        $linkedInvoices = $invoicesTable->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'Invoices.advance_id' => $invoice->id,
            ])
            ->contain(['Providers', 'Employees'])
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

        $vm = new AdvanceLegalizationViewModel(
            $invoice,
            $leg,
            $roleName,
            $linkedInvoices,
            $bankingEntities,
            $surplusPayment,
            (int)$user->id,
            $this->actionPolicy,
            (int)$user->role_id,
        );
        $this->set($vm->build());
        $this->set('actionPolicy', $this->actionPolicy);

        return null;
    }

    /**
     * The Anticipo is an Invoice; edit lives in InvoicesController.
     */
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
    public function linkCandidates(?int $id = null): ?Response
    {
        $this->request->allowMethod(['get']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canLinkInvoices($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            return $this->_denyAction((int)$id);
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $advance = $invoicesTable->get($leg->advance_invoice_id, [
            'fields' => ['id', 'operation_center_id'],
        ]);

        $filters = [
            'date_from' => $this->request->getQuery('date_from') ?: '',
            'date_to' => $this->request->getQuery('date_to') ?: '',
            'provider_id' => $this->request->getQuery('provider_id') ?: '',
            'operation_center_id' => $this->request->getQuery('operation_center_id')
                ?: ($advance->operation_center_id ?? ''),
        ];

        $conditions = [
            'Invoices.document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'Invoices.advance_id IS' => null,
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
    public function linkInvoices(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canLinkInvoices($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            return $this->_denyAction((int)$id);
        }
        $userId = (int)$this->_getCurrentUser()->id;

        $invoiceIds = (array)$this->request->getData('invoice_ids', []);
        $invoiceIds = array_values(array_filter(array_map('intval', $invoiceIds)));

        $result = $this->legalizationService->linkInvoices($leg, $invoiceIds, $userId);
        if ($result->success) {
            $this->Flash->success(($result->data['linked'] ?? 0) . ' factura(s) vinculada(s).');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al vincular.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Unlink a single Legalización invoice (POST).
     */
    public function unlinkInvoice(?int $id = null, ?int $invoiceId = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canUnlinkInvoice($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->unlinkInvoice($leg, (int)$invoiceId, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->Flash->success('Factura desvinculada.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al desvincular.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Upload the relation-of-invoices document (POST multipart).
     */
    public function uploadRelationDocument(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canUploadRelationDocument($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
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

            return $this->redirect(['action' => 'view', $id]);
        }

        $result = $this->documentService->attachRelationDocument($leg, $file, (int)$this->_getCurrentUser()->id);

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

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Move legalization from validacion → revision_firmas (POST).
     */
    public function moveToRevision(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canMoveToRevision($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->moveToRevisionFirmas($leg, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->Flash->success('Legalización enviada a Revisión y Firmas.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al avanzar.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Mark relation document as signed and advance to contabilidad (POST).
     */
    public function markSigned(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canMarkSigned($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->markSigned($leg, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->Flash->success('Documento marcado como firmado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al firmar.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Reject signature and bounce back to validacion (POST).
     */
    public function returnToValidacion(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canReturnToValidacion($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            return $this->_denyAction((int)$id);
        }
        $reason = (string)$this->request->getData('reason', '');
        $result = $this->legalizationService->returnToValidacion($leg, $reason, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->Flash->success('Legalización devuelta a Validación.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al devolver.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Close legalization as caso exacto (POST).
     */
    public function markExact(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canMarkExact($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->markExact($leg, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->Flash->success('Anticipo legalizado (caso exacto).');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al legalizar.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Contabilidad declares a shortage and pushes legalization to Tesorería (POST).
     */
    public function registerShortage(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canRegisterShortage($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            return $this->_denyAction((int)$id);
        }
        if (!$this->_ensureExpectedStatus($leg->status)) {
            return $this->redirect(['action' => 'view', $id]);
        }
        $amount = $this->_parseCop((string)$this->request->getData('shortage_amount'));
        $result = $this->legalizationService->registerShortage($leg, $amount, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->Flash->success('Faltante registrado. La legalización pasó a Tesorería.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al registrar faltante.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Tesorería confirms beneficiary's shortage deposit (POST multipart).
     */
    public function confirmShortage(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canConfirmShortage($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => 'No tienes permiso para esta acción en el estado actual.',
                ]);
            }

            return $this->_denyAction((int)$id);
        }
        $data = $this->request->getData();
        $data['receipt_file'] = $this->request->getUploadedFile('receipt_file');
        $result = $this->legalizationService->confirmShortageReceipt(
            $leg,
            $data,
            (int)$this->_getCurrentUser()->id,
        );

        if ($this->_isJsonRequest()) {
            if ($result->success) {
                return $this->_jsonResponse(['success' => true]);
            }

            return $this->_jsonResponse(['success' => false, 'error' => $result->firstError() ?? 'Error al confirmar consignación.']);
        }

        if ($result->success) {
            $this->Flash->success('Consignación confirmada. Anticipo legalizado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al confirmar consignación.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Contabilidad declares a surplus and pushes legalization to Tesorería (POST).
     */
    public function registerSurplus(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canRegisterSurplus($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            return $this->_denyAction((int)$id);
        }
        if (!$this->_ensureExpectedStatus($leg->status)) {
            return $this->redirect(['action' => 'view', $id]);
        }
        $amount = $this->_parseCop((string)$this->request->getData('surplus_amount'));
        $result = $this->legalizationService->registerSurplus($leg, $amount, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->Flash->success('Sobrante registrado. La legalización pasó a Tesorería.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al registrar sobrante.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Tesorería registers a refund payment to the beneficiary (POST).
     */
    public function registerRefund(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        if (!$this->actionPolicy->canRegisterRefund($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
            return $this->_denyAction((int)$id);
        }
        $data = $this->request->getData();
        $result = $this->legalizationService->registerRefundPayment(
            $leg,
            $data,
            (int)$this->_getCurrentUser()->id,
        );
        if ($result->success) {
            $this->Flash->success('Reintegro registrado. Pendiente de autorización por el Contador.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al registrar reintegro.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Tesorería confirma que el reintegro al beneficiario ya se ejecutó.
     * Cierra la legalización (caso sobrante) de `verificacion_pago` → `legalizada`.
     */
    public function confirmRefundPayment(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);
        if (!$this->actionPolicy->canConfirmRefundPayment($leg, (int)$user->id, $roleName)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->confirmRefundExecuted($leg, (int)$user->id);
        if ($result->success) {
            $this->Flash->success('Reintegro confirmado. La legalización quedó cerrada.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al confirmar el reintegro.');
        }

        return $this->redirect(['action' => 'legalization', $id]);
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

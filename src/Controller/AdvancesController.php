<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Model\Entity\AdvanceLegalization;
use App\Service\AdvanceLegalizationService;
use App\Service\InvoicePipelineService;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;

class AdvancesController extends AppController
{
    use DocumentJsonPayloadTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private AdvanceLegalizationService $legalizationService;

    private InvoicePipelineService $pipelineService;

    public function initialize(): void
    {
        parent::initialize();
        $this->legalizationService = $this->getContainer()->get(AdvanceLegalizationService::class);
        $this->pipelineService = $this->getContainer()->get(InvoicePipelineService::class);
        $this->fetchTable('Invoices');
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    /**
     * "Mis Anticipos" — filtra por los pipeline_status visibles del rol.
     */
    public function index(): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $roleName = $this->_getUserRoleName($this->_getCurrentUser());
        $visibleStatuses = $this->pipelineService->getVisibleAdvanceStatuses($roleName);

        $query = $invoicesTable->find()
            ->where(['Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->contain([
                'Providers',
                'Employees',
                'OperationCenters',
                'AdvanceLegalization',
            ])
            ->order(['Invoices.created' => 'DESC']);

        if (!empty($visibleStatuses)) {
            $query->where(['Invoices.pipeline_status IN' => $visibleStatuses]);
        }

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
            ->order(['Invoices.created' => 'DESC']);

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

        $query = $invoicesTable->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            ])
            ->contain([
                'Providers',
                'Employees',
                'OperationCenters',
                'AdvanceLegalization',
            ])
            ->matching('AdvanceLegalization', function ($q) {
                return $q->where([
                    'AdvanceLegalization.status !=' => AdvanceConstants::STATUS_LEGALIZADA,
                ]);
            })
            ->order(['Invoices.created' => 'DESC']);

        $advances = $this->paginate($query);
        $visibleStatuses = [];
        $this->set(compact('advances', 'visibleStatuses'));
        $this->render('index');
    }

    public function add(): ?Response
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoicesTable->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();
            $data['document_type'] = InvoiceConstants::DOCTYPE_ANTICIPO;
            $data['registered_by'] = $user->id;
            $data['pipeline_status'] = InvoiceConstants::STATUS_APROBACION;
            $data['registration_date'] = date('Y-m-d');
            // Anticipos no tienen fecha de vencimiento; usamos la de emisión.
            if (empty($data['due_date']) && !empty($data['issue_date'])) {
                $data['due_date'] = $data['issue_date'];
            }

            // beneficiary required: provider_id OR employee_id
            if (empty($data['provider_id']) && empty($data['employee_id'])) {
                $this->Flash->error('Debe seleccionar un proveedor o un empleado como beneficiario.');
            } else {
                $invoice = $invoicesTable->patchEntity($invoice, $data);
                if ($invoicesTable->save($invoice)) {
                    $this->Flash->success('Anticipo creado.');

                    return $this->redirect(['action' => 'view', $invoice->id]);
                }
                $this->Flash->error('No se pudo guardar el anticipo.');
            }
        }

        $this->set(compact('invoice'));
        $this->set($this->_dropdowns());

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
            $this->Flash->info('La legalización aún no ha iniciado. Espere a que el anticipo esté en estado Pagada.');

            return $this->redirect(['action' => 'view', $invoice->id]);
        }

        // Facturas vinculadas
        $linkedInvoices = $invoicesTable->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'Invoices.advance_id' => $invoice->id,
            ])
            ->contain(['Providers', 'Employees'])
            ->order(['Invoices.issue_date' => 'ASC'])
            ->all();

        $linkedTotal = 0.0;
        foreach ($linkedInvoices as $li) {
            $linkedTotal += (float)$li->amount;
        }
        $advanceTotal = (float)$invoice->amount;
        $diff = $advanceTotal - $linkedTotal;

        // Documento "Relación de facturas" actual (signature pendiente o firmada más reciente)
        $relationDocument = null;
        $signatureHistory = [];
        if ($leg->advance_legalization_signatures) {
            $sigs = $leg->advance_legalization_signatures;
            usort($sigs, fn($a, $b) => $b->id <=> $a->id);
            foreach ($sigs as $sig) {
                if (
                    $relationDocument === null && in_array(
                        $sig->signature_status,
                        [AdvanceConstants::SIGNATURE_PENDING, AdvanceConstants::SIGNATURE_SIGNED],
                        true,
                    )
                ) {
                    $relationDocument = $sig;
                } else {
                    $signatureHistory[] = $sig;
                }
            }
        }

        // Refund payment + banking entities (caso sobrante)
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

        $roleName = $this->_getCurrentUser()->role->name ?? '';

        $this->set(compact(
            'invoice',
            'leg',
            'linkedInvoices',
            'linkedTotal',
            'advanceTotal',
            'diff',
            'relationDocument',
            'signatureHistory',
            'bankingEntities',
            'surplusPayment',
            'roleName',
        ));

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
     * Bulk-link Legalización invoices to this advance (POST).
     */
    public function linkInvoices(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
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
        $file = $this->request->getUploadedFile('relation_document');
        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'Adjunte un archivo PDF de relación de facturas.']);
            }
            $this->Flash->error('Adjunte un archivo PDF de relación de facturas.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $result = $this->legalizationService->attachRelationDocument($leg, $file, (int)$this->_getCurrentUser()->id);

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
        $raw = (string)$this->request->getData('shortage_amount');
        $amount = (float)str_replace([',', '.'], ['.', ''], $raw);
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
        $raw = (string)$this->request->getData('surplus_amount');
        $amount = (float)str_replace([',', '.'], ['.', ''], $raw);
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
     * Resolve the AdvanceLegalization tied to a given Anticipo invoice id.
     */
    private function _loadLegalization(int $advanceInvoiceId): AdvanceLegalization
    {
        return TableRegistry::getTableLocator()
            ->get('AdvanceLegalizations')
            ->find()
            ->where(['advance_invoice_id' => $advanceInvoiceId])
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function _dropdowns(): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        return [
            'providers' => $invoicesTable->Providers->find('list')->order(['Providers.name' => 'ASC'])->all(),
            'operationCenters' => $invoicesTable->OperationCenters->find('codeList')->all(),
            'expenseTypes' => $invoicesTable->ExpenseTypes->find('list')->all(),
            'costCenters' => $invoicesTable->CostCenters->find('codeList')->all(),
            'employees' => $this->fetchTable('Employees')
                ->find('list', keyField: 'id', valueField: 'full_name')
                ->order(['Employees.first_name' => 'ASC', 'Employees.last_name1' => 'ASC'])
                ->toArray(),
        ];
    }
}

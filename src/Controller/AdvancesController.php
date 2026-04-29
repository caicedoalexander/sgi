<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\AdvanceLegalizationService;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;

class AdvancesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private AdvanceLegalizationService $legalizationService;

    public function initialize(): void
    {
        parent::initialize();
        $this->fetchTable('Invoices');
        $this->legalizationService = new AdvanceLegalizationService();
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    public function index(): void
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

        $this->set(compact('advances'));
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
            'InvoiceObservations' => ['Users'],
            'InvoiceDocuments' => ['UploadedByUsers'],
            'InvoicePayments' => ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
            'AdvanceLegalization' => ['AdvanceLegalizationSignatures' => ['SignedByUsers']],
        ]);

        if ($invoice->document_type !== InvoiceConstants::DOCTYPE_ANTICIPO) {
            $this->Flash->error('Esta factura no es un Anticipo.');

            return $this->redirect(['action' => 'index']);
        }

        // Linked Legalización-Invoices
        $linkedInvoices = $invoicesTable->find()
            ->where([
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'advance_id' => $invoice->id,
            ])
            ->contain(['Providers', 'Employees'])
            ->order(['issue_date' => 'ASC'])
            ->all();

        $linkedTotal = 0.0;
        foreach ($linkedInvoices as $li) {
            $linkedTotal += (float)$li->amount;
        }

        $this->set(compact('invoice', 'linkedInvoices', 'linkedTotal'));

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
            $this->Flash->error('Adjunte un archivo PDF de relación de facturas.');

            return $this->redirect(['action' => 'view', $id]);
        }
        $result = $this->legalizationService->attachRelationDocument($leg, $file, (int)$this->_getCurrentUser()->id);
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
            'expenseTypes' => $invoicesTable->ExpenseTypes->find('list', limit: 200)->all(),
            'costCenters' => $invoicesTable->CostCenters->find('codeList')->all(),
            'employees' => $this->fetchTable('Employees')
                ->find('list', limit: 500)
                ->order(['Employees.first_name' => 'ASC'])
                ->all(),
        ];
    }
}

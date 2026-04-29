<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;

class AdvancesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function initialize(): void
    {
        parent::initialize();
        $this->fetchTable('Invoices');
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

    public function add(): void
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
    }

    public function view(?int $id = null): void
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
    }

    /**
     * The Anticipo is an Invoice; edit lives in InvoicesController.
     */
    public function edit(?int $id = null): Response
    {
        return $this->redirect(['controller' => 'Invoices', 'action' => 'edit', $id]);
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

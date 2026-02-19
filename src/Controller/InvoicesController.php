<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\InvoiceHistoryService;
use App\Service\InvoicePipelineService;

class InvoicesController extends AppController
{
    private InvoicePipelineService $pipeline;
    private InvoiceHistoryService $historyService;

    public function initialize(): void
    {
        parent::initialize();
        $this->pipeline = new InvoicePipelineService();
        $this->historyService = new InvoiceHistoryService();
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    private function _getRoleName(): string
    {
        $user = $this->_getCurrentUser();
        return $user->role->name ?? 'Admin';
    }

    public function index()
    {
        $roleName = $this->_getRoleName();
        $visibleStatuses = $this->pipeline->getVisibleStatuses($roleName);

        $query = $this->Invoices->find()
            ->contain(['Providers', 'OperationCenters', 'ExpenseTypes', 'CostCenters', 'RegisteredByUsers']);

        if (!empty($visibleStatuses)) {
            $query->where(['pipeline_status IN' => $visibleStatuses]);
        }

        $invoices = $this->paginate($query);

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
    }

    public function view($id = null)
    {
        $invoice = $this->Invoices->get($id, contain: [
            'Providers',
            'OperationCenters',
            'ExpenseTypes',
            'CostCenters',
            'ConfirmedByUsers',
            'RegisteredByUsers',
            'ApproverUsers',
            'InvoiceHistories' => ['Users'],
        ]);

        $this->set(compact('invoice'));
    }

    public function add()
    {
        $invoice = $this->Invoices->newEmptyEntity();
        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();
            $data['registered_by'] = $user->id;
            $data['pipeline_status'] = $data['pipeline_status'] ?? 'revision';

            $invoice = $this->Invoices->patchEntity($invoice, $data);
            if ($this->Invoices->save($invoice)) {
                $this->Flash->success(__('La factura ha sido guardada.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar la factura. Intente de nuevo.'));
        }

        $providers = $this->Invoices->Providers->find('list', limit: 200)->all();
        $operationCenters = $this->Invoices->OperationCenters->find('codeList')->all();
        $expenseTypes = $this->Invoices->ExpenseTypes->find('list', limit: 200)->all();
        $costCenters = $this->Invoices->CostCenters->find('codeList')->all();

        $this->set(compact('invoice', 'providers', 'operationCenters', 'expenseTypes', 'costCenters'));
    }

    public function edit($id = null)
    {
        $invoice = $this->Invoices->get($id, contain: ['Providers', 'OperationCenters']);
        $roleName = $this->_getRoleName();
        $currentStatus = $invoice->pipeline_status;

        $editableFields = $this->pipeline->getEditableFields($roleName, $currentStatus);
        $canAdvance = $this->pipeline->canAdvance($roleName, $currentStatus);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $user = $this->_getCurrentUser();
            $originalInvoice = clone $invoice;
            $data = $this->request->getData();

            // Filter data to only editable fields for this role
            $filteredData = $this->pipeline->filterEntityData($data, $roleName, $currentStatus);
            $invoice = $this->Invoices->patchEntity($invoice, $filteredData);

            if ($this->Invoices->save($invoice)) {
                $this->historyService->recordChanges($originalInvoice, $invoice, $user->id);
                $this->Flash->success(__('La factura ha sido actualizada.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar la factura. Intente de nuevo.'));
        }

        $providers = $this->Invoices->Providers->find('list', limit: 200)->all();
        $operationCenters = $this->Invoices->OperationCenters->find('codeList')->all();
        $expenseTypes = $this->Invoices->ExpenseTypes->find('list', limit: 200)->all();
        $costCenters = $this->Invoices->CostCenters->find('codeList')->all();
        $approvers = $this->Invoices->ApproverUsers->find('list', limit: 200)->all();

        $pipelineStatuses = InvoicePipelineService::STATUSES;
        $pipelineLabels = InvoicePipelineService::STATUS_LABELS;

        $this->set(compact(
            'invoice', 'providers', 'operationCenters', 'expenseTypes', 'costCenters',
            'approvers', 'editableFields', 'canAdvance', 'roleName',
            'pipelineStatuses', 'pipelineLabels', 'currentStatus'
        ));
    }

    public function advanceStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);
        $roleName = $this->_getRoleName();
        $user = $this->_getCurrentUser();
        $currentStatus = $invoice->pipeline_status;

        if (!$this->pipeline->canAdvance($roleName, $currentStatus)) {
            $this->Flash->error('No tiene permisos para avanzar esta factura.');
            return $this->redirect(['action' => 'edit', $id]);
        }

        $nextStatus = $this->pipeline->getNextStatus($currentStatus);
        if (!$nextStatus) {
            $this->Flash->error('Esta factura ya está en el estado final.');
            return $this->redirect(['action' => 'edit', $id]);
        }

        $invoice->pipeline_status = $nextStatus;
        if ($this->Invoices->save($invoice)) {
            $this->historyService->recordStatusChange($invoice->id, $currentStatus, $nextStatus, $user->id);
            $this->Flash->success(sprintf(
                'Factura avanzada a: %s',
                InvoicePipelineService::STATUS_LABELS[$nextStatus]
            ));
        } else {
            $this->Flash->error('No se pudo avanzar el estado.');
        }

        return $this->redirect(['action' => 'index']);
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $invoice = $this->Invoices->get($id);
        if ($this->Invoices->delete($invoice)) {
            $this->Flash->success(__('La factura ha sido eliminada.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar la factura. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}

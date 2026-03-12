<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use App\Service\ExcelService;
use App\Service\InvoiceDocumentService;
use App\Service\InvoiceFilterService;
use App\Service\InvoiceHistoryService;
use App\Service\InvoicePipelineService;
use ArrayObject;
use Cake\ORM\TableRegistry;

class InvoicesController extends AppController
{
    private InvoicePipelineService $pipeline;
    private InvoiceFilterService $filterService;
    private InvoiceDocumentService $documentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->pipeline = new InvoicePipelineService();
        $this->filterService = new InvoiceFilterService();
        $this->documentService = new InvoiceDocumentService();
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    private function _getRoleName(): string
    {
        return $this->_getUserRoleName($this->_getCurrentUser());
    }

    public function index()
    {
        $roleName = $this->_getRoleName();
        $visibleStatuses = $this->pipeline->getVisibleStatuses($roleName);
        $userId = (int)$this->_getCurrentUser()->id;

        $conditions = !empty($visibleStatuses)
            ? ['Invoices.pipeline_status IN' => $visibleStatuses]
            : [];

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInvoiceQuery($conditions, $userId));

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set($this->_getFilterDropdowns());
    }

    public function all()
    {
        $roleName = $this->_getRoleName();
        $userId = (int)$this->_getCurrentUser()->id;

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInvoiceQuery([], $userId));
        $visibleStatuses = [];

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set($this->_getFilterDropdowns());
        $this->render('index');
    }

    public function rejected(): void
    {
        $roleName = $this->_getRoleName();
        $userId = (int)$this->_getCurrentUser()->id;

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInvoiceQuery([
            'Invoices.area_approval' => InvoiceConstants::APPROVAL_REJECTED,
        ], $userId));
        $visibleStatuses = [];

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set($this->_getFilterDropdowns());
        $this->render('index');
    }

    public function view($id = null)
    {
        $this->fetchTable('InvoiceReads')->markAsRead((int)$id, (int)$this->_getCurrentUser()->id);

        $invoice = $this->Invoices->get($id, contain: [
            'Providers',
            'OperationCenters',
            'ExpenseTypes',
            'CostCenters',
            'ConfirmedByUsers',
            'RegisteredByUsers',
            'ApproverUsers',
            'PettyCashRecords',
            'InvoiceHistories' => ['Users'],
            'InvoiceObservations' => [
                'Users',
                'sort' => ['InvoiceObservations.created' => 'ASC'],
            ],
            'InvoiceDocuments' => [
                'UploadedByUsers',
                'sort' => ['InvoiceDocuments.created' => 'DESC'],
            ],
        ]);

        $roleName = $this->_getRoleName();
        $isRejected = $this->pipeline->isRejected($invoice);
        $pipelineStatuses = InvoicePipelineService::STATUSES;
        $pipelineLabels = InvoicePipelineService::STATUS_LABELS;

        $documentsByStatus = [];
        foreach ($invoice->invoice_documents as $doc) {
            $documentsByStatus[$doc->pipeline_status][] = $doc;
        }

        $fieldLabels = InvoiceHistoryService::FIELD_LABELS;
        $this->set(compact('invoice', 'roleName', 'isRejected', 'pipelineStatuses', 'pipelineLabels', 'documentsByStatus', 'fieldLabels'));
    }

    public function add()
    {
        $invoice = $this->Invoices->newEmptyEntity();
        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();
            $data['registered_by'] = $user->id;
            $data['pipeline_status'] = 'aprobacion';
            $data['registration_date'] = date('Y-m-d');

            $invoice = $this->Invoices->patchEntity($invoice, $data);
            if ($this->Invoices->save($invoice)) {
                $this->Flash->success(__('La factura ha sido guardada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar la factura. Intente de nuevo.'));
        }

        $this->set(compact('invoice'));
        $this->set($this->_getFormDropdowns());
    }

    public function edit($id = null)
    {
        $this->fetchTable('InvoiceReads')->markAsRead((int)$id, (int)$this->_getCurrentUser()->id);

        $invoice = $this->Invoices->get($id, contain: [
            'Providers',
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
        ]);

        if ($invoice->isInPettyCash()) {
            $this->Flash->warning(sprintf(
                'Esta factura pertenece al registro de Caja Menor %s. Los cambios de estado se gestionan desde allí.',
                $invoice->petty_cash_record->code ?? '#' . $invoice->petty_cash_record_id,
            ));
        }

        $roleName = $this->_getRoleName();
        $currentStatus = $invoice->pipeline_status;

        $editableFields = $this->pipeline->getEditableFields($roleName, $currentStatus);
        $canAdvance = $this->pipeline->canAdvance($roleName, $currentStatus);
        $visibleSections = $this->pipeline->getVisibleSections($roleName, $currentStatus);
        $isRejected = $this->pipeline->isRejected($invoice);

        // Pre-compute advance errors for GET
        $advanceErrors = [];
        $nextStatus = null;
        if ($canAdvance && !$isRejected) {
            $advanceErrors = $this->pipeline->validateTransitionRequirements($invoice, $currentStatus);
            if (empty($advanceErrors)) {
                $nextStatus = $this->pipeline->getNextStatus($currentStatus);
            }
        }

        if ($this->request->is(['patch', 'post', 'put'])) {
            $user = $this->_getCurrentUser();
            $result = $this->pipeline->saveAndAdvance(
                $invoice,
                $this->request->getData(),
                $roleName,
                $user->id,
                $this->_getBaseUrl(),
            );

            if ($result['saved']) {
                if ($result['advanced']) {
                    $nextLabel = InvoicePipelineService::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
                    $this->Flash->success(sprintf('Factura guardada y avanzada a: %s', $nextLabel));
                } else {
                    $this->Flash->success('La factura ha sido actualizada.');
                    foreach ($result['advanceErrors'] as $err) {
                        $this->Flash->warning($err);
                    }
                }

                if (!empty($result['approvalLinkSent'])) {
                    $this->Flash->success('Se envió el enlace de aprobación al aprobador por correo.');
                }
                foreach ($result['notificationErrors'] as $notifErr) {
                    $this->Flash->warning($notifErr);
                }

                return $this->redirect(['action' => 'edit', $id]);
            }

            $this->Flash->error('No se pudo guardar la factura. Verifique los datos e intente de nuevo.');
        }

        $pipelineStatuses = InvoicePipelineService::STATUSES;
        $pipelineLabels = InvoicePipelineService::STATUS_LABELS;

        $canDeleteDocuments = $this->_checkPermission('invoices', 'delete');

        $this->set(compact(
            'invoice',
            'editableFields',
            'canAdvance',
            'canDeleteDocuments',
            'roleName',
            'pipelineStatuses',
            'pipelineLabels',
            'currentStatus',
            'visibleSections',
            'isRejected',
            'advanceErrors',
            'nextStatus',
        ));
        $this->set($this->_getFormDropdowns());
    }

    public function advanceStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);

        if ($invoice->isInPettyCash()) {
            $this->Flash->error('No se puede avanzar individualmente una factura agrupada en Caja Menor.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();

        $result = $this->pipeline->advance($invoice, $this->_getRoleName(), $user->id);

        if ($result['success']) {
            $nextLabel = InvoicePipelineService::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
            $this->Flash->success(sprintf('Factura avanzada a: %s', $nextLabel));
            if (!empty($result['notificationError'])) {
                $this->Flash->warning($result['notificationError']);
            }

            return $this->redirect(['action' => 'edit', $id]);
        }

        $this->Flash->error($result['error']);

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function generateApprovalLink($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id, contain: ['Providers']);
        $user = $this->_getCurrentUser();

        if (empty($invoice->approver_id)) {
            $this->Flash->error('Debe asignar un aprobador antes de generar el enlace.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $baseUrl = $this->_getBaseUrl();
        $result = $this->pipeline->trySendApprovalLink($invoice, $user->id, $baseUrl);

        if ($result['success']) {
            $this->Flash->success('Enlace de aprobación enviado por correo al aprobador (válido por 48h).');
        } else {
            $this->Flash->error('Error al enviar el enlace de aprobación: ' . $result['error']);
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    private function _getBaseUrl(): string
    {
        $scheme = $this->request->getHeaderLine('X-Forwarded-Proto') ?: $this->request->scheme();

        return $scheme . '://' . $this->request->host();
    }

    public function addObservation($id = null)
    {
        $this->request->allowMethod(['post']);
        $user = $this->_getCurrentUser();

        $observationsTable = $this->fetchTable('InvoiceObservations');
        $observation = $observationsTable->newEntity([
            'invoice_id' => $id,
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

    public function export()
    {
        $roleName = $this->_getRoleName();
        $visibleStatuses = $this->pipeline->getVisibleStatuses($roleName);
        $pipelineLabels = InvoicePipelineService::STATUS_LABELS;

        $query = $this->Invoices->find()
            ->contain(['Providers', 'OperationCenters', 'ExpenseTypes', 'CostCenters'])
            ->order(['Invoices.registration_date' => 'DESC']);

        if (!empty($visibleStatuses)) {
            $query->where(['Invoices.pipeline_status IN' => $visibleStatuses]);
        }

        $query->formatResults(function ($results) use ($pipelineLabels) {
            return $results->map(function ($invoice) use ($pipelineLabels) {
                return new ArrayObject([
                    'Número Factura'      => $invoice->invoice_number ?? '',
                    'Tipo Documento'      => $invoice->document_type ?? '',
                    'Fecha Registro'      => $invoice->registration_date?->format('Y-m-d') ?? '',
                    'Fecha Emisión'       => $invoice->issue_date?->format('Y-m-d') ?? '',
                    'Fecha Vencimiento'   => $invoice->due_date?->format('Y-m-d') ?? '',
                    'Proveedor'           => $invoice->provider->name ?? '',
                    'Centro Operación'    => $invoice->operation_center->name ?? '',
                    'Tipo Gasto'          => $invoice->expense_type->name ?? '',
                    'Centro Costos'       => $invoice->cost_center->name ?? '',
                    'Detalle'             => $invoice->detail ?? '',
                    'Valor'               => $invoice->amount ?? 0,
                    'Validación DIAN'     => $invoice->dian_validation ?? '',
                    'Causada'             => $invoice->accrued ? 'Sí' : 'No',
                    'Fecha Causación'     => $invoice->accrual_date?->format('Y-m-d') ?? '',
                    'Lista para Pago'     => $invoice->ready_for_payment ?? '',
                    'Estado Pago'         => $invoice->payment_status ?? '',
                    'Fecha Pago'          => $invoice->payment_date?->format('Y-m-d') ?? '',
                    'Estado Pipeline'     => $pipelineLabels[$invoice->pipeline_status] ?? $invoice->pipeline_status ?? '',
                ]);
            });
        });

        $excelService = new ExcelService();
        $filePath = $excelService->exportCatalog('Facturas', $query);

        $response = $this->response->withFile($filePath, [
            'download' => true,
            'name' => 'facturas_' . date('Y-m-d') . '.xlsx',
        ]);

        register_shutdown_function(function () use ($filePath) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        });

        return $response;
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

    private function _buildInvoiceQuery(array $conditions = [], ?int $userId = null): \Cake\ORM\Query\SelectQuery
    {
        $query = $this->Invoices->find()
            ->contain(['Providers', 'OperationCenters', 'ExpenseTypes', 'CostCenters', 'RegisteredByUsers']);

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        if ($userId !== null) {
            $query->selectAlso([
                'unread_observations' => "(
                    SELECT COUNT(*)
                    FROM invoice_observations io
                    LEFT JOIN invoice_reads ir
                        ON ir.invoice_id = io.invoice_id AND ir.user_id = $userId
                    WHERE io.invoice_id = Invoices.id
                      AND io.user_id != $userId
                      AND (ir.last_visited_at IS NULL OR io.created > ir.last_visited_at)
                )",
            ]);
        }

        $this->filterService->apply($query, $this->request->getQueryParams());

        return $query;
    }

    private function _getFilterDropdowns(): array
    {
        return [
            'providers' => $this->Invoices->Providers->find('list', limit: 200)->all(),
            'operationCenters' => $this->Invoices->OperationCenters->find('codeList')->all(),
            'expenseTypes' => $this->Invoices->ExpenseTypes->find('list', limit: 200)->all(),
        ];
    }

    private function _getFormDropdowns(): array
    {
        $activeApproverIds = $this->fetchTable('Approvers')
            ->find()
            ->select(['user_id'])
            ->where(['active' => true]);

        return [
            'providers' => $this->Invoices->Providers->find('list', limit: 200)->all(),
            'operationCenters' => $this->Invoices->OperationCenters->find('codeList')->all(),
            'expenseTypes' => $this->Invoices->ExpenseTypes->find('list', limit: 200)->all(),
            'costCenters' => $this->Invoices->CostCenters->find('codeList')->all(),
            'approvers' => $this->Invoices->ApproverUsers
                ->find('list', limit: 200)
                ->where(['ApproverUsers.id IN' => $activeApproverIds])
                ->all(),
        ];
    }

    public function uploadDocument($invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($invoiceId);

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            $this->Flash->error(__('No se recibió ningún archivo válido.'));

            return $this->redirect(['action' => 'edit', $invoiceId]);
        }

        $identity = $this->Authentication->getIdentity();
        $result = $this->documentService->uploadDocument(
            (int)$invoiceId,
            $invoice->pipeline_status,
            $file,
            $identity ? (int)$identity->getIdentifier() : null,
            $this->request->getData('document_type'),
        );

        if (is_string($result)) {
            $this->Flash->error(__($result));
        } else {
            $this->Flash->success(__('El soporte ha sido subido.'));
        }

        return $this->redirect(['action' => 'edit', $invoiceId]);
    }

    public function deleteDocument($invoiceId = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $invoice = $this->Invoices->get($invoiceId);

        $documentsTable = TableRegistry::getTableLocator()->get('InvoiceDocuments');
        $document = $documentsTable->get($documentId);

        if (!$this->documentService->canDeleteDocument($document, $invoice->pipeline_status)) {
            $this->Flash->error(__('No se puede eliminar un soporte de un estado anterior.'));

            return $this->redirect(['action' => 'view', $invoiceId]);
        }

        if ($this->documentService->deleteDocument((int)$documentId)) {
            $this->Flash->success(__('El soporte ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el soporte.'));
        }

        return $this->redirect(['action' => 'view', $invoiceId]);
    }
}

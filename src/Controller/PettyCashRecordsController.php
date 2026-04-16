<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\PettyCashConstants;
use App\Constants\RoleConstants;
use App\Service\PettyCashDocumentService;
use App\Service\PettyCashService;

class PettyCashRecordsController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private PettyCashService $pettyCashService;
    private PettyCashDocumentService $documentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->pettyCashService = new PettyCashService();
        $this->documentService = new PettyCashDocumentService();
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    public function index()
    {
        $query = $this->PettyCashRecords->find()
            ->contain(['CreatedByUsers', 'Invoices'])
            ->order(['PettyCashRecords.created' => 'DESC']);

        // Filters
        $params = $this->request->getQueryParams();

        if (!empty($params['code'])) {
            $query->where(['PettyCashRecords.code LIKE' => '%' . $params['code'] . '%']);
        }
        if (!empty($params['status'])) {
            $query->where(['PettyCashRecords.status' => $params['status']]);
        }
        if (!empty($params['date_from'])) {
            $query->where(['PettyCashRecords.created >=' => $params['date_from']]);
        }
        if (!empty($params['date_to'])) {
            $query->where(['PettyCashRecords.created <=' => $params['date_to'] . ' 23:59:59']);
        }

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $records = $this->paginate($query);
        $this->set(compact('records'));
    }

    public function view($id = null)
    {
        $record = $this->PettyCashRecords->get($id, contain: [
            'CreatedByUsers',
            'Invoices' => ['Providers'],
            'PettyCashDocuments' => [
                'UploadedByUsers',
                'sort' => ['PettyCashDocuments.created' => 'DESC'],
            ],
            'PettyCashObservations' => [
                'Users',
                'sort' => ['PettyCashObservations.created' => 'ASC'],
            ],
        ]);

        $this->set(compact('record'));
    }

    public function add()
    {
        $record = $this->PettyCashRecords->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();

            $invoiceIds = array_map('intval', array_filter((array)($data['invoice_ids'] ?? [])));

            $record = $this->PettyCashRecords->patchEntity($record, [
                'code' => !empty($data['code']) ? $data['code'] : null,
                'status' => PettyCashConstants::STATUS_AGRUPACION,
                'total_amount' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if ($this->PettyCashRecords->save($record)) {
                if (!empty($invoiceIds)) {
                    $errors = $this->pettyCashService->addInvoices($record, $invoiceIds);
                    foreach ($errors as $err) {
                        $this->Flash->warning($err);
                    }
                }

                $this->Flash->success('Registro de Caja Menor creado exitosamente.');

                return $this->redirect(['action' => 'edit', $record->id]);
            }

            $this->Flash->error('No se pudo crear el registro. Intente de nuevo.');
        }

        $groupFilters = $this->request->getQueryParams();
        $availableInvoices = $this->pettyCashService->getAvailableInvoices($groupFilters)->all();
        $operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();
        $this->set(compact('record', 'availableInvoices', 'operationCenters', 'groupFilters'));
    }

    public function edit($id = null)
    {
        $record = $this->PettyCashRecords->get($id, contain: [
            'CreatedByUsers',
            'Invoices' => ['Providers', 'OperationCenters'],
            'PettyCashDocuments' => [
                'UploadedByUsers',
                'sort' => ['PettyCashDocuments.created' => 'DESC'],
            ],
            'PettyCashObservations' => [
                'Users',
                'sort' => ['PettyCashObservations.created' => 'ASC'],
            ],
            'PettyCashPayments' => ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
        ]);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();
            $patchData = [];

            // Code: editable in all non-final states
            if (!$record->isPagado()) {
                $patchData['code'] = !empty($data['code']) ? $data['code'] : null;
            }

            // Notes: editable in agrupacion and contabilidad
            if ($record->isAgrupacion() || $record->isContabilidad()) {
                $patchData['notes'] = $data['notes'] ?? $record->notes;
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

            // Treasury fields: editable in tesoreria
            if ($record->isTesoreria()) {
                $patchData['payment_status'] = $data['payment_status'] ?? null;
                $patchData['payment_date'] = !empty($data['payment_date']) ? $data['payment_date'] : null;
            }

            if (!empty($patchData)) {
                $record = $this->PettyCashRecords->patchEntity($record, $patchData);
                $this->PettyCashRecords->save($record);
            }

            // Add invoices (only in agrupacion)
            if ($record->isAgrupacion() && !empty($data['invoice_ids'])) {
                $invoiceIds = array_map('intval', array_filter((array)$data['invoice_ids']));
                $errors = $this->pettyCashService->addInvoices($record, $invoiceIds);
                foreach ($errors as $err) {
                    $this->Flash->warning($err);
                }
            }

            // Try to advance automatically (save + advance unified)
            $user = $this->_getCurrentUser();
            $canAdvance = !$record->isPagado() && (PettyCashConstants::TRANSITIONS[$record->status] ?? null) !== null;
            $advanced = false;
            if ($canAdvance) {
                $result = $this->pettyCashService->advanceStatus($record, $user->id);
                if ($result['success']) {
                    $advanced = true;
                    $nextLabel = PettyCashConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
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
        $nextStatus = PettyCashConstants::TRANSITIONS[$record->status] ?? null;
        $advanceErrors = [];
        if ($nextStatus) {
            $advanceErrors = $this->pettyCashService->getTransitionErrors($record);
        }

        $groupFilters = $this->request->getQueryParams();
        $availableInvoices = $this->pettyCashService->getAvailableInvoices($groupFilters)->all();
        $operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();
        $canDeleteDocuments = $this->_checkPermission('petty_cash', 'delete');

        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);
        $bankingEntities = $this->fetchTable('BankingEntities')->find('list')->toArray();
        $isTesoreriaEdit = ($roleName === RoleConstants::TESORERIA || $roleName === RoleConstants::ADMIN)
            && $record->status === PettyCashConstants::STATUS_TESORERIA;
        $isContadorAutPago = ($roleName === RoleConstants::CONTADOR || $roleName === RoleConstants::ADMIN)
            && $record->status === PettyCashConstants::STATUS_AUT_PAGO;

        $this->set(compact('record', 'availableInvoices', 'operationCenters', 'canDeleteDocuments', 'groupFilters', 'nextStatus', 'advanceErrors', 'roleName', 'bankingEntities', 'isTesoreriaEdit', 'isContadorAutPago'));
    }

    public function advanceStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($id);
        $user = $this->_getCurrentUser();

        $result = $this->pettyCashService->advanceStatus($record, $user->id);

        if ($result['success']) {
            $nextLabel = PettyCashConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
            $this->Flash->success(sprintf('Registro avanzado a: %s', $nextLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result['error']);

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->PettyCashRecords->get($id);

        if (!$this->pettyCashService->canDelete($record)) {
            $this->Flash->error('Solo se pueden eliminar registros en estado Agrupación.');

            return $this->redirect(['action' => 'index']);
        }

        // Unlink invoices first
        $invoicesTable = $this->fetchTable('Invoices');
        $invoicesTable->updateAll(
            ['petty_cash_record_id' => null],
            ['petty_cash_record_id' => $record->id],
        );

        if ($this->PettyCashRecords->delete($record)) {
            $this->Flash->success('Registro de Caja Menor eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el registro.');
        }

        return $this->redirect(['action' => 'index']);
    }

    public function removeInvoice($recordId = null, $invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($recordId);

        if ($this->pettyCashService->removeInvoice($record, (int)$invoiceId)) {
            $this->Flash->success('Factura removida del registro.');
        } else {
            $this->Flash->error('No se puede remover facturas de un registro que no esté en Agrupación.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }

    public function uploadDocument($id = null)
    {
        $this->request->allowMethod(['post']);
        $this->PettyCashRecords->get($id); // Verify exists

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            $this->Flash->error('No se recibió ningún archivo válido.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $identity = $this->Authentication->getIdentity();
        $result = $this->documentService->uploadDocument(
            (int)$id,
            $file,
            $identity ? (int)$identity->getIdentifier() : null,
            $this->request->getData('document_type'),
        );

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('El soporte ha sido subido.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function addObservation($id = null)
    {
        $this->request->allowMethod(['post']);
        $user = $this->_getCurrentUser();

        $observationsTable = $this->fetchTable('PettyCashObservations');
        $observation = $observationsTable->newEntity([
            'petty_cash_record_id' => $id,
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

    public function deleteDocument($recordId = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $this->PettyCashRecords->get($recordId); // Verify exists

        if ($this->documentService->deleteDocument((int)$documentId)) {
            $this->Flash->success('El soporte ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }
}

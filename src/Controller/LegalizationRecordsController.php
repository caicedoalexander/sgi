<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\LegalizationConstants;
use App\Service\LegalizationDocumentService;
use App\Service\LegalizationService;

class LegalizationRecordsController extends AppController
{
    private LegalizationService $legalizationService;
    private LegalizationDocumentService $documentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->legalizationService = new LegalizationService();
        $this->documentService = new LegalizationDocumentService();
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    public function index()
    {
        $query = $this->LegalizationRecords->find()
            ->contain(['CreatedByUsers', 'Invoices'])
            ->order(['LegalizationRecords.created' => 'DESC']);

        $params = $this->request->getQueryParams();

        if (!empty($params['code'])) {
            $query->where(['LegalizationRecords.code LIKE' => '%' . $params['code'] . '%']);
        }
        if (!empty($params['status'])) {
            $query->where(['LegalizationRecords.status' => $params['status']]);
        }
        if (!empty($params['date_from'])) {
            $query->where(['LegalizationRecords.created >=' => $params['date_from']]);
        }
        if (!empty($params['date_to'])) {
            $query->where(['LegalizationRecords.created <=' => $params['date_to'] . ' 23:59:59']);
        }

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $records = $this->paginate($query);
        $this->set(compact('records'));
    }

    public function view($id = null)
    {
        $record = $this->LegalizationRecords->get($id, contain: [
            'CreatedByUsers',
            'Invoices' => ['Providers'],
            'LegalizationDocuments' => [
                'UploadedByUsers',
                'sort' => ['LegalizationDocuments.created' => 'DESC'],
            ],
            'LegalizationObservations' => [
                'Users',
                'sort' => ['LegalizationObservations.created' => 'ASC'],
            ],
        ]);

        $this->set(compact('record'));
    }

    public function add()
    {
        $record = $this->LegalizationRecords->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();

            $invoiceIds = array_map('intval', array_filter((array)($data['invoice_ids'] ?? [])));

            $record = $this->LegalizationRecords->patchEntity($record, [
                'code' => !empty($data['code']) ? $data['code'] : null,
                'status' => LegalizationConstants::STATUS_AGRUPACION,
                'total_amount' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if ($this->LegalizationRecords->save($record)) {
                if (!empty($invoiceIds)) {
                    $errors = $this->legalizationService->addInvoices($record, $invoiceIds);
                    foreach ($errors as $err) {
                        $this->Flash->warning($err);
                    }
                }

                $this->Flash->success('Registro de Legalización creado exitosamente.');

                return $this->redirect(['action' => 'edit', $record->id]);
            }

            $this->Flash->error('No se pudo crear el registro. Intente de nuevo.');
        }

        $groupFilters = $this->request->getQueryParams();
        $availableInvoices = $this->legalizationService->getAvailableInvoices($groupFilters)->all();
        $operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();
        $this->set(compact('record', 'availableInvoices', 'operationCenters', 'groupFilters'));
    }

    public function edit($id = null)
    {
        $record = $this->LegalizationRecords->get($id, contain: [
            'CreatedByUsers',
            'Invoices' => ['Providers', 'OperationCenters'],
            'LegalizationDocuments' => [
                'UploadedByUsers',
                'sort' => ['LegalizationDocuments.created' => 'DESC'],
            ],
            'LegalizationObservations' => [
                'Users',
                'sort' => ['LegalizationObservations.created' => 'ASC'],
            ],
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
                $patchData['accrued'] = !empty($data['accrued']);
                if (!empty($data['accrued']) && empty($record->accrual_date)) {
                    $patchData['accrual_date'] = date('Y-m-d');
                } elseif (empty($data['accrued'])) {
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
                $record = $this->LegalizationRecords->patchEntity($record, $patchData);
                $this->LegalizationRecords->save($record);
            }

            // Add invoices (only in agrupacion)
            if ($record->isAgrupacion() && !empty($data['invoice_ids'])) {
                $invoiceIds = array_map('intval', array_filter((array)$data['invoice_ids']));
                $errors = $this->legalizationService->addInvoices($record, $invoiceIds);
                foreach ($errors as $err) {
                    $this->Flash->warning($err);
                }
            }

            // Try to advance automatically
            $user = $this->_getCurrentUser();
            $nextTrans = LegalizationConstants::TRANSITIONS[$record->status] ?? null;
            $canAdvance = !$record->isPagado() && $nextTrans !== null;
            if ($canAdvance) {
                $result = $this->legalizationService->advanceStatus($record, $user->id);
                if ($result['success']) {
                    $nextLabel = LegalizationConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
                    $this->Flash->success(sprintf('Registro guardado y avanzado a: %s', $nextLabel));
                } else {
                    $this->Flash->success('Registro actualizado.');
                    $this->Flash->warning($result['error']);
                }
            } else {
                $this->Flash->success('Registro actualizado.');
            }

            return $this->redirect(['action' => 'edit', $id]);
        }

        $nextStatus = LegalizationConstants::TRANSITIONS[$record->status] ?? null;
        $advanceErrors = [];
        if ($nextStatus) {
            $advanceErrors = $this->legalizationService->getTransitionErrors($record);
        }

        $groupFilters = $this->request->getQueryParams();
        $availableInvoices = $this->legalizationService->getAvailableInvoices($groupFilters)->all();
        $operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();
        $canDeleteDocuments = $this->_checkPermission('legalizations', 'delete');

        $this->set(compact(
            'record', 'availableInvoices', 'operationCenters',
            'canDeleteDocuments', 'groupFilters', 'nextStatus', 'advanceErrors',
        ));
    }

    public function advanceStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->LegalizationRecords->get($id);
        $user = $this->_getCurrentUser();

        $result = $this->legalizationService->advanceStatus($record, $user->id);

        if ($result['success']) {
            $nextLabel = LegalizationConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
            $this->Flash->success(sprintf('Registro avanzado a: %s', $nextLabel));
        } else {
            $this->Flash->error($result['error']);
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->LegalizationRecords->get($id);

        if (!$this->legalizationService->canDelete($record)) {
            $this->Flash->error('Solo se pueden eliminar registros en estado Agrupación.');

            return $this->redirect(['action' => 'index']);
        }

        $invoicesTable = $this->fetchTable('Invoices');
        $invoicesTable->updateAll(
            ['legalization_record_id' => null],
            ['legalization_record_id' => $record->id],
        );

        if ($this->LegalizationRecords->delete($record)) {
            $this->Flash->success('Registro de Legalización eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el registro.');
        }

        return $this->redirect(['action' => 'index']);
    }

    public function removeInvoice($recordId = null, $invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->LegalizationRecords->get($recordId);

        if ($this->legalizationService->removeInvoice($record, (int)$invoiceId)) {
            $this->Flash->success('Factura removida del registro.');
        } else {
            $this->Flash->error('No se puede remover facturas de un registro que no esté en Agrupación.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }

    public function uploadDocument($id = null)
    {
        $this->request->allowMethod(['post']);
        $this->LegalizationRecords->get($id);

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

        $observationsTable = $this->fetchTable('LegalizationObservations');
        $observation = $observationsTable->newEntity([
            'legalization_record_id' => $id,
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
        $this->LegalizationRecords->get($recordId);

        if ($this->documentService->deleteDocument((int)$documentId)) {
            $this->Flash->success('El soporte ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }
}

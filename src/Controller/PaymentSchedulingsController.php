<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\PaymentSchedulingConstants;
use App\Service\PaymentSchedulingPipelineService;
use App\Service\PaymentSchedulingService;

class PaymentSchedulingsController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private PaymentSchedulingPipelineService $pipeline;

    private PaymentSchedulingService $schedulingService;

    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->pipeline = $container->get(PaymentSchedulingPipelineService::class);
        $this->schedulingService = $container->get(PaymentSchedulingService::class);
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

        $query = $this->PaymentSchedulings->find()
            ->contain(['CreatedByUsers', 'PaymentSchedulingItems'])
            ->order(['PaymentSchedulings.created' => 'DESC']);

        if (!empty($visibleStatuses)) {
            $query->where(['PaymentSchedulings.pipeline_status IN' => $visibleStatuses]);
        }

        // Filters
        $params = $this->request->getQueryParams();
        if (!empty($params['code'])) {
            $query->where(['PaymentSchedulings.code LIKE' => '%' . $params['code'] . '%']);
        }
        if (!empty($params['status'])) {
            $query->where(['PaymentSchedulings.pipeline_status' => $params['status']]);
        }

        $records = $this->paginate($query);
        $this->set(compact('records', 'roleName'));
    }

    public function view($id = null)
    {
        $record = $this->PaymentSchedulings->get($id, contain: [
            'CreatedByUsers',
            'PaymentSchedulingItems' => [
                'Invoices' => ['Providers'],
                'BankingEntities',
            ],
            'PaymentSchedulingAttachments' => [
                'UploadedByUsers',
                'sort' => ['PaymentSchedulingAttachments.created' => 'DESC'],
            ],
            'PaymentSchedulingObservations' => [
                'Users',
                'sort' => ['PaymentSchedulingObservations.created' => 'ASC'],
            ],
        ]);

        $roleName = $this->_getRoleName();
        $total = $this->schedulingService->calculateTotal($record->id);
        $pipelineLabels = PaymentSchedulingConstants::STATUS_LABELS;

        $this->set(compact('record', 'roleName', 'total', 'pipelineLabels'));
    }

    public function add()
    {
        $record = $this->PaymentSchedulings->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();
            $data['code'] = $this->PaymentSchedulings->generateNextCode();
            $data['pipeline_status'] = PaymentSchedulingConstants::STATUS_BORRADOR;
            $data['created_by'] = $user->id;

            $record = $this->PaymentSchedulings->patchEntity($record, $data);
            if ($this->PaymentSchedulings->save($record)) {
                $this->Flash->success('Programación creada correctamente.');

                return $this->redirect(['action' => 'edit', $record->id]);
            }
            $this->Flash->error('No se pudo crear la programación.');
        }

        $this->set(compact('record'));
    }

    public function edit($id = null)
    {
        $record = $this->PaymentSchedulings->get($id, contain: [
            'CreatedByUsers',
            'PaymentSchedulingItems' => [
                'Invoices' => ['Providers'],
                'BankingEntities',
            ],
            'PaymentSchedulingAttachments' => [
                'UploadedByUsers',
                'sort' => ['PaymentSchedulingAttachments.created' => 'DESC'],
            ],
            'PaymentSchedulingObservations' => [
                'Users',
                'sort' => ['PaymentSchedulingObservations.created' => 'ASC'],
            ],
        ]);

        $roleName = $this->_getRoleName();
        $currentStatus = $record->pipeline_status;
        $canAdvance = $this->pipeline->canAdvance($roleName, $currentStatus);
        $canReject = $this->pipeline->canReject($roleName, $currentStatus);
        $total = $this->schedulingService->calculateTotal($record->id);

        $advanceErrors = [];
        if ($canAdvance) {
            $advanceErrors = $this->pipeline->validateTransitionRequirements($record, $currentStatus);
        }

        $pipelineLabels = PaymentSchedulingConstants::STATUS_LABELS;
        $nextStatus = $this->pipeline->getNextStatus($currentStatus);
        $bankingEntities = $this->fetchTable('BankingEntities')->find('list')->all();

        $canRegress = $this->pipeline->canRegress($roleName, $currentStatus);
        $previousStatus = $this->pipeline->getPreviousStatus($currentStatus);
        $regressLockMessage = $this->pipeline->getRegressionLockMessage($record);

        $this->set(compact(
            'record',
            'roleName',
            'currentStatus',
            'canAdvance',
            'canReject',
            'total',
            'advanceErrors',
            'pipelineLabels',
            'nextStatus',
            'bankingEntities',
            'canRegress',
            'previousStatus',
            'regressLockMessage',
        ));
    }

    public function advance($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        if (!$this->_ensureExpectedStatus($record->pipeline_status)) {
            return $this->redirect(['action' => 'edit', $id]);
        }

        $roleName = $this->_getRoleName();
        $user = $this->_getCurrentUser();

        if (!$this->pipeline->canAdvance($roleName, $record->pipeline_status)) {
            $this->Flash->error('No tiene permisos para avanzar esta programación.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $errors = $this->pipeline->validateTransitionRequirements($record, $record->pipeline_status);
        if (!empty($errors)) {
            foreach ($errors as $err) {
                $this->Flash->error($err);
            }

            return $this->redirect(['action' => 'edit', $id]);
        }

        $nextStatus = $this->pipeline->getNextStatus($record->pipeline_status);

        // Si avanza a pagada (desde aut_pago), aplicar pagos
        if ($nextStatus === PaymentSchedulingConstants::STATUS_PAGADA) {
            $result = $this->schedulingService->applyPayments($record->id, (int)$user->id);
            if (!$result['success']) {
                foreach ($result['errors'] as $err) {
                    $this->Flash->error($err);
                }

                return $this->redirect(['action' => 'edit', $id]);
            }

            $advancedCount = count($result['advanced_to_pagada']);
            $partialCount = count($result['partial_payment']);
            if ($advancedCount > 0) {
                $this->Flash->success("{$advancedCount} factura(s) marcadas como Pagadas.");
            }
            if ($partialCount > 0) {
                $this->Flash->warning("{$partialCount} factura(s) con Pago Parcial, permanecen en Tesorería.");
            }
        }

        $record->pipeline_status = $nextStatus;
        if ($this->PaymentSchedulings->save($record)) {
            $label = PaymentSchedulingConstants::STATUS_LABELS[$nextStatus] ?? $nextStatus;
            $this->Flash->success("Programación avanzada a: {$label}");

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error('No se pudo avanzar la programación.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function reject($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);
        $roleName = $this->_getRoleName();

        if (!$this->pipeline->canReject($roleName, $record->pipeline_status)) {
            $this->Flash->error('No tiene permisos para rechazar esta programación.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $record->pipeline_status = PaymentSchedulingPipelineService::REJECTION_TARGET;
        if ($this->PaymentSchedulings->save($record)) {
            $this->Flash->warning('Programación devuelta a Tesorería para corrección.');
        } else {
            $this->Flash->error('No se pudo rechazar la programación.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function regressStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);
        $user = $this->_getCurrentUser();
        $roleName = $this->_getRoleName();
        $reason = trim((string)$this->request->getData('reason', ''));

        $result = $this->pipeline->regress(
            $record,
            $roleName,
            (int)$user->id,
            $reason,
        );

        if ($result->success) {
            $previousStatus = $result->data['previousStatus'] ?? null;
            $prevLabel = PaymentSchedulingConstants::STATUS_LABELS[$previousStatus] ?? $previousStatus;
            $this->Flash->success(sprintf('Programación regresada a: %s', $prevLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo regresar la programación.');

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function importExcel($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        if (!in_array($record->pipeline_status, [PaymentSchedulingConstants::STATUS_BORRADOR])) {
            $this->Flash->error('Solo se puede importar Excel en estado Borrador.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        /** @var \Laminas\Diactoros\UploadedFile $file */
        $file = $this->request->getUploadedFile('excel_file');
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error('No se recibió un archivo válido.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        $result = $this->schedulingService->parseExcel($tmpPath);

        // Guardar resultados en sesión para confirmar
        $this->request->getSession()->write("import_preview_{$id}", $result);

        return $this->redirect(['action' => 'previewImport', $id]);
    }

    public function previewImport($id = null)
    {
        $record = $this->PaymentSchedulings->get($id);
        $result = $this->request->getSession()->read("import_preview_{$id}");

        if (!$result) {
            $this->Flash->error('No hay datos de importación pendientes.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $this->set(compact('record', 'result'));
    }

    public function confirmImport($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        $result = $this->request->getSession()->read("import_preview_{$id}");
        if (!$result || empty($result['valid'])) {
            $this->Flash->error('No hay datos válidos para importar.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        if ($this->schedulingService->linkItems($record->id, $result['valid'])) {
            $count = count($result['valid']);
            $this->Flash->success("{$count} factura(s) vinculadas correctamente.");
        } else {
            $this->Flash->error('Error al vincular las facturas.');
        }

        $this->request->getSession()->delete("import_preview_{$id}");

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function addItem($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        if ($record->pipeline_status !== PaymentSchedulingConstants::STATUS_BORRADOR) {
            $this->Flash->error('Solo se pueden agregar facturas en estado Borrador.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $itemsTable = $this->fetchTable('PaymentSchedulingItems');
        $item = $itemsTable->newEntity([
            'payment_scheduling_id' => $id,
            'invoice_id' => $this->request->getData('invoice_id'),
            'banking_entity_id' => $this->request->getData('banking_entity_id'),
            'amount' => $this->request->getData('amount'),
        ]);

        if ($itemsTable->save($item)) {
            $this->Flash->success('Factura vinculada.');
        } else {
            $this->Flash->error('No se pudo vincular la factura.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function removeItem($id = null, $itemId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->PaymentSchedulings->get($id);

        if ($record->pipeline_status !== PaymentSchedulingConstants::STATUS_BORRADOR) {
            $this->Flash->error('Solo se pueden eliminar facturas en estado Borrador.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $itemsTable = $this->fetchTable('PaymentSchedulingItems');
        $item = $itemsTable->get($itemId);

        if ($itemsTable->delete($item)) {
            $this->Flash->success('Factura desvinculada.');
        } else {
            $this->Flash->error('No se pudo desvincular la factura.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function uploadAttachment($id = null)
    {
        $this->request->allowMethod(['post']);
        $this->PaymentSchedulings->get($id);

        $file = $this->request->getUploadedFile('file');
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error('No se recibió un archivo válido.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $fileName = $file->getClientFilename();
        $uploadDir = WWW_ROOT . 'uploads' . DS . 'payment_schedulings' . DS . $id;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $targetPath = $uploadDir . DS . $fileName;
        $file->moveTo($targetPath);

        $relativePath = 'uploads/payment_schedulings/' . $id . '/' . $fileName;

        $attachmentsTable = $this->fetchTable('PaymentSchedulingAttachments');
        $attachment = $attachmentsTable->newEntity([
            'payment_scheduling_id' => $id,
            'file_path' => $relativePath,
            'file_name' => $fileName,
            'uploaded_by' => $this->_getCurrentUser()->id,
        ]);

        if ($attachmentsTable->save($attachment)) {
            $this->Flash->success('Soporte subido correctamente.');
        } else {
            $this->Flash->error('No se pudo guardar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function deleteAttachment($id = null, $attachmentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $attachmentsTable = $this->fetchTable('PaymentSchedulingAttachments');
        $attachment = $attachmentsTable->get($attachmentId);

        $filePath = WWW_ROOT . $attachment->file_path;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        if ($attachmentsTable->delete($attachment)) {
            $this->Flash->success('Soporte eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function addObservation($id = null)
    {
        $this->request->allowMethod(['post']);
        $user = $this->_getCurrentUser();

        $observationsTable = $this->fetchTable('PaymentSchedulingObservations');
        $observation = $observationsTable->newEntity([
            'payment_scheduling_id' => $id,
            'user_id' => $user->id,
            'message' => $this->request->getData('message'),
        ]);

        if ($this->request->is('ajax')) {
            if ($observationsTable->save($observation)) {
                return $this->_jsonResponse([
                    'success' => true,
                    'observation' => [
                        'message' => $observation->message,
                        'user_name' => $user->full_name,
                        'created' => $observation->created->format('d/m/Y H:i'),
                    ],
                ]);
            }

            return $this->_jsonResponse(['success' => false, 'error' => 'No se pudo agregar la observación.']);
        }

        if ($observationsTable->save($observation)) {
            $this->Flash->success('Observación agregada.');
        } else {
            $this->Flash->error('No se pudo agregar la observación.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }
}

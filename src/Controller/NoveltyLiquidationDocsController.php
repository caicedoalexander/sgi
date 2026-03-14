<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\NoveltyConstants;
use App\Service\NoveltyDocumentService;
use App\Service\NoveltyObservationService;
use App\Service\NoveltyPipelineService;
use App\Service\NoveltySignatureService;
use DateTime;

class NoveltyLiquidationDocsController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private NoveltyPipelineService $pipelineService;
    private NoveltyDocumentService $documentService;
    private NoveltyObservationService $observationService;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->pipelineService = new NoveltyPipelineService();
        $this->documentService = new NoveltyDocumentService();
        $this->observationService = new NoveltyObservationService();
    }

    /**
     * @return \Cake\Http\Response|null|void
     */
    public function index()
    {
        $query = $this->NoveltyLiquidationDocs->find()
            ->contain(['PerformedByUsers', 'EmployeeNovelties'])
            ->order(['NoveltyLiquidationDocs.created' => 'DESC']);

        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['NoveltyLiquidationDocs.pipeline_status' => $statusFilter]);
        }

        $liquidationDocs = $this->paginate($query);
        $this->set(compact('liquidationDocs', 'statusFilter'));
    }

    /**
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null|void
     */
    public function view(?string $id = null)
    {
        $doc = $this->NoveltyLiquidationDocs->get($id, contain: [
            'PerformedByUsers',
            'CreatedByUsers',
            'EmployeeNovelties' => ['Employees', 'NoveltyTypes'],
            'NoveltyLiquidationSignatures' => ['SignedByUsers'],
            'NoveltyObservations' => [
                'Users',
                'sort' => ['NoveltyObservations.created' => 'ASC'],
            ],
            'NoveltyDocuments' => [
                'UploadedByUsers',
                'sort' => ['NoveltyDocuments.created' => 'DESC'],
            ],
        ]);

        $user = $this->Authentication->getIdentity()->getOriginalData();
        $this->observationService->markAsRead($user->id, liquidationDocId: $doc->id);

        $groupErrors = $this->pipelineService->validateGroupTransition($doc);
        $effectiveStatuses = $this->pipelineService->getEffectiveStatuses();

        $documentsByStatus = $this->documentService->getGroupDocumentsByStatus($doc->id);

        $this->set(compact('doc', 'groupErrors', 'effectiveStatuses', 'documentsByStatus'));
    }

    /**
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null
     */
    public function advanceGroup(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $doc = $this->NoveltyLiquidationDocs->get($id);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        // Save editable fields for current stage before advancing
        $data = $this->request->getData();
        if (!empty($data)) {
            $doc = $this->NoveltyLiquidationDocs->patchEntity($doc, $data);
            $this->NoveltyLiquidationDocs->save($doc);
        }

        $result = $this->pipelineService->advanceGroup($doc, $user->id);

        if ($result['success']) {
            $label = NoveltyConstants::STATUS_LABELS[$result['nextStatus']];
            $this->Flash->success('Documento de liquidación avanzado a: ' . $label);
        } else {
            foreach ($result['errors'] as $error) {
                $this->Flash->error($error);
            }
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null
     */
    public function addSignature(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $signerType = $this->request->getData('signer_type');
        $signatureService = new NoveltySignatureService();
        $user = $this->Authentication->getIdentity()->getOriginalData();

        $signaturesTable = $this->fetchTable('NoveltyLiquidationSignatures');
        $signature = $signaturesTable->find()
            ->where(['liquidation_doc_id' => $id, 'signer_type' => $signerType])
            ->first();

        if (!$signature) {
            $this->Flash->error('Firma no encontrada.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $signatureBase64 = $this->request->getData('signature_base64');
        if (!empty($signatureBase64)) {
            $path = $signatureService->saveFromBase64(
                $id,
                $signatureBase64,
                $user->id,
                'liquidation_' . $signerType,
            );
            if ($path) {
                $signature->signature_path = $path;
                $signature->signed_by = $user->id;
                $signature->approved_at = new DateTime();
                $signaturesTable->save($signature);
                $this->Flash->success('Firma registrada.');
            }
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null
     */
    public function uploadDocument(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $doc = $this->NoveltyLiquidationDocs->get($id);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $file = $this->request->getUploadedFile('document');

        if (!$file) {
            $this->Flash->error('No se seleccionó ningún archivo.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $result = $this->documentService->uploadForGroup($doc->id, $doc->pipeline_status, $file, $user->id);

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Documento subido exitosamente.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Document ID.
     * @param string|null $documentId Document attachment ID.
     * @return \Cake\Http\Response|null
     */
    public function deleteDocument(?string $id = null, ?string $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $doc = $this->NoveltyLiquidationDocs->get($id);

        $documentsTable = $this->fetchTable('NoveltyDocuments');
        $document = $documentsTable->get($documentId);

        if (!$this->documentService->canDeleteDocument($document, $doc->pipeline_status)) {
            $this->Flash->error('Solo puede eliminar documentos de la etapa actual.');

            return $this->redirect(['action' => 'view', $id]);
        }

        if ($this->documentService->deleteDocument($documentId)) {
            $this->Flash->success('Documento eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el documento.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null
     */
    public function addObservation(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $message = $this->request->getData('message');

        $result = $this->observationService->addToGroup($id, $user->id, $message);

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Observación agregada.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }
}

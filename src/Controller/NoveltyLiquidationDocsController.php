<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Controller\Trait\ObservationControllerTrait;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Model\Entity\User;
use App\Service\NoveltyDocumentService;
use App\Service\NoveltyHistoryService;
use App\Service\NoveltyObservationService;
use App\Service\NoveltyService;
use App\Service\NoveltySignatureService;
use App\Service\PipelineAuthorizationService;
use App\View\Presentation\NoveltyPresentation;
use App\ViewModel\NoveltyLiquidationDocEditViewModel;
use Cake\Routing\Router;
use DateTime;

class NoveltyLiquidationDocsController extends AppController
{
    use DocumentJsonPayloadTrait;
    use ObservationControllerTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private NoveltyService $pipelineService;

    private NoveltyDocumentService $documentService;

    private NoveltyObservationService $observationService;

    private NoveltySignatureService $signatureService;

    private PipelineAuthorizationService $pipelineAuth;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->pipelineService = $container->get(NoveltyService::class);
        $this->documentService = $container->get(NoveltyDocumentService::class);
        $this->observationService = $container->get(NoveltyObservationService::class);
        $this->signatureService = $container->get(NoveltySignatureService::class);
        $this->pipelineAuth = $container->get(PipelineAuthorizationService::class);
    }

    /**
     * "Mis D. de Liquidación" — filters by the statuses visible to the user's role.
     * Optional ?pipeline_status=... narrows down within that set.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function index()
    {
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $roleId = (int)$user->role_id;
        $visibleStatuses = $this->pipelineService->getVisibleLiquidationStatuses($roleId);

        $query = $this->NoveltyLiquidationDocs->find()
            ->contain(['PerformedByUsers', 'EmployeeNovelties'])
            ->orderBy(['NoveltyLiquidationDocs.created' => 'DESC']);

        $query->where($this->_visibleStatusConditions('NoveltyLiquidationDocs.pipeline_status', $visibleStatuses));

        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['NoveltyLiquidationDocs.pipeline_status' => $statusFilter]);
        }

        $liquidationDocs = $this->paginate($query);
        $this->set(compact('liquidationDocs', 'statusFilter', 'visibleStatuses'));
    }

    /**
     * "Todos los D. de Liquidación" — no role filter, optional ?pipeline_status=...
     *
     * @return \Cake\Http\Response|null|void
     */
    public function all()
    {
        $query = $this->NoveltyLiquidationDocs->find()
            ->contain(['PerformedByUsers', 'EmployeeNovelties'])
            ->orderBy(['NoveltyLiquidationDocs.created' => 'DESC']);

        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['NoveltyLiquidationDocs.pipeline_status' => $statusFilter]);
        }

        $liquidationDocs = $this->paginate($query);
        $visibleStatuses = [];
        $this->set(compact('liquidationDocs', 'statusFilter', 'visibleStatuses'));
        $this->render('index');
    }

    /**
     * "Rechazadas" — pipeline_status = 'rechazada'.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function rejected()
    {
        $query = $this->NoveltyLiquidationDocs->find()
            ->contain(['PerformedByUsers', 'EmployeeNovelties'])
            ->where(['NoveltyLiquidationDocs.pipeline_status' => NoveltyConstants::STATUS_RECHAZADA])
            ->orderBy(['NoveltyLiquidationDocs.created' => 'DESC']);

        $liquidationDocs = $this->paginate($query);
        $statusFilter = NoveltyConstants::STATUS_RECHAZADA;
        $visibleStatuses = [];
        $this->set(compact('liquidationDocs', 'statusFilter', 'visibleStatuses'));
        $this->render('index');
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
            'LiquidationDocPayments' => ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
        ]);

        $user = $this->Authentication->getIdentity()->getOriginalData();
        $this->observationService->markAsRead($user->id, liquidationDocId: $doc->id);

        $groupErrors = $this->pipelineService->validateGroupTransition($doc);
        $firstNovelty = $doc->employee_novelties[0] ?? null;
        $noveltyType = $firstNovelty?->novelty_type;
        $effectiveStatuses = $this->pipelineService->getEffectiveStatuses($noveltyType);

        $documentsByStatus = $this->documentService->getGroupDocumentsByStatus($doc->id);
        $liquidationDocument = $this->documentService->getLiquidationDocument($doc->id);

        // Aggregate change history from all novelties in this group
        $noveltyIds = array_map(fn($n) => $n->id, $doc->employee_novelties);
        $groupHistories = [];
        $fieldLabels = NoveltyHistoryService::FIELD_LABELS;
        if (!empty($noveltyIds)) {
            $historiesTable = $this->fetchTable('NoveltyHistories');
            $groupHistories = $historiesTable->find()
                ->contain(['Users', 'EmployeeNovelties'])
                ->where(['NoveltyHistories.novelty_id IN' => $noveltyIds])
                ->orderBy(['NoveltyHistories.created' => 'DESC'])
                ->all()
                ->toArray();
        }

        $this->set(compact(
            'doc',
            'groupErrors',
            'effectiveStatuses',
            'documentsByStatus',
            'liquidationDocument',
            'groupHistories',
            'fieldLabels',
        ));
    }

    /**
     * Edit/advance a liquidation document.
     *
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null|void
     */
    public function edit(?string $id = null)
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
            'LiquidationDocPayments' => ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
        ]);

        $user = $this->Authentication->getIdentity()->getOriginalData();
        $this->observationService->markAsRead($user->id, liquidationDocId: $doc->id);

        $roleName = $this->_getUserRoleName($user);
        $vm = $this->_buildLiquidationEditViewModel($doc, $user, $roleName);
        $this->set('viewModel', $vm);
    }

    /**
     * Build the view model for the edit action.
     */
    private function _buildLiquidationEditViewModel(
        NoveltyLiquidationDoc $doc,
        User $user,
        string $roleName,
    ): NoveltyLiquidationDocEditViewModel {
        $groupErrors = $this->pipelineService->validateGroupTransition($doc);
        $firstNovelty = $doc->employee_novelties[0] ?? null;
        $noveltyType = $firstNovelty?->novelty_type;
        $skipsGdp = $noveltyType && !$noveltyType->requires_employee_signature_review;
        $effectiveStatuses = $this->pipelineService->getEffectiveStatuses($noveltyType);
        $documentsByStatus = $this->documentService->getGroupDocumentsByStatus($doc->id);
        $liquidationDocument = $this->documentService->getLiquidationDocument($doc->id);

        $bankingEntities = $this->fetchTable('BankingEntities')->find('list')->toArray();
        $roleId = (int)$user->role_id;
        $canOpTesoreria = $this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_NOVELTIES,
            NoveltyConstants::STATUS_TESORERIA,
        );
        $canOpAutPago = $this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_NOVELTIES,
            NoveltyConstants::STATUS_AUTORIZACION_PAGO,
        );
        $canConfirmPayment = $this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_NOVELTIES,
            NoveltyConstants::STATUS_VERIFICACION_PAGO,
        );
        $canRegisterPayment = $canOpTesoreria
            && $doc->pipeline_status === NoveltyConstants::STATUS_TESORERIA;
        $canAuthorizePayment = $canOpAutPago
            && $doc->pipeline_status === NoveltyConstants::STATUS_AUTORIZACION_PAGO;

        return new NoveltyLiquidationDocEditViewModel(
            doc: $doc,
            roleName: $roleName,
            groupErrors: $groupErrors,
            effectiveStatuses: $effectiveStatuses,
            documentsByStatus: $documentsByStatus,
            liquidationDocument: $liquidationDocument,
            currentUser: $user,
            skipsGdp: (bool)$skipsGdp,
            bankingEntities: $bankingEntities,
            canRegisterPayment: $canRegisterPayment,
            canAuthorizePayment: $canAuthorizePayment,
            canConfirmPayment: $canConfirmPayment,
        );
    }

    /**
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null
     */
    public function advanceGroup(?string $id = null): ?Response
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

        if ($result->success) {
            $nextStatus = $result->data['nextStatus'] ?? null;
            $label = NoveltyConstants::STATUS_LABELS[$nextStatus] ?? $nextStatus;
            $this->Flash->success('Documento de liquidación avanzado a: ' . $label);

            return $this->redirect(['action' => 'index']);
        }

        foreach ($result->errors as $error) {
            $this->Flash->error($error);
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null
     */
    public function addSignature(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $signerType = $this->request->getData('signer_type');
        $signatureStatus = $this->request->getData('signature_status');
        $user = $this->Authentication->getIdentity()->getOriginalData();

        $signaturesTable = $this->fetchTable('NoveltyLiquidationSignatures');
        $signature = $signaturesTable->find()
            ->where(['liquidation_doc_id' => $id, 'signer_type' => $signerType])
            ->first();

        if (!$signature) {
            $this->Flash->error('Firma no encontrada.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        if ($signatureStatus === 'signed') {
            $signature->signature_path = 'marked_as_signed';
            $signature->signed_by = $user->id;
            $signature->approved_at = new DateTime();
            $signaturesTable->save($signature);
            $this->Flash->success('Firma marcada como firmada.');
        } elseif ($signatureStatus === 'pending') {
            $signature->signature_path = null;
            $signature->signed_by = null;
            $signature->approved_at = null;
            $signaturesTable->save($signature);
            $this->Flash->success('Firma marcada como pendiente.');
        } else {
            $this->Flash->error('Estado de firma no válido.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null
     */
    public function uploadDocument(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $doc = $this->NoveltyLiquidationDocs->get($id);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $file = $this->request->getUploadedFile('file');

        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se seleccionó ningún archivo.']);
            }
            $this->Flash->error('No se seleccionó ningún archivo.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $result = $this->documentService->uploadForGroup($doc->id, $doc->pipeline_status, $file, $user->id);

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            $canDelete = $this->documentService->canDeleteDocument($result, $doc->pipeline_status);
            [$badgeColors, $statusLabels] = $this->_liquidationDocumentLabels();
            $deleteUrl = $canDelete
                ? Router::url(['action' => 'deleteDocument', $doc->id, $result->id])
                : null;

            return $this->_jsonResponse([
                'success' => true,
                'document' => $this->_buildDocumentPayload(
                    $result,
                    $canDelete,
                    $deleteUrl,
                    $badgeColors,
                    $statusLabels,
                ),
            ]);
        }

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Documento subido exitosamente.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * Upload the dedicated liquidation document.
     *
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null
     */
    public function uploadLiquidationDocument(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $doc = $this->NoveltyLiquidationDocs->get($id);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $file = $this->request->getUploadedFile('liquidation_file');

        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se seleccionó ningún archivo.']);
            }
            $this->Flash->error('No se seleccionó ningún archivo.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $result = $this->documentService->uploadLiquidationDocument($doc->id, $file, $user->id);

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            return $this->_jsonResponse(['success' => true]);
        }

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Documento de liquidación subido exitosamente.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * Update (replace) the dedicated liquidation document.
     *
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null
     */
    public function updateLiquidationDocument(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $doc = $this->NoveltyLiquidationDocs->get($id);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        $allowedStatuses = [
            NoveltyConstants::STATUS_CONTABILIDAD,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
        ];

        if (!in_array($doc->pipeline_status, $allowedStatuses)) {
            $msg = 'No se puede actualizar el documento en este estado.';
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => $msg]);
            }
            $this->Flash->error($msg);

            return $this->redirect(['action' => 'edit', $id]);
        }

        $file = $this->request->getUploadedFile('liquidation_file');

        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se seleccionó ningún archivo.']);
            }
            $this->Flash->error('No se seleccionó ningún archivo.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $result = $this->documentService->updateLiquidationDocument($doc->id, $file, $user->id);

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            return $this->_jsonResponse(['success' => true]);
        }

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Documento de liquidación actualizado exitosamente.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id Document ID.
     * @param string|null $documentId Document attachment ID.
     * @return \Cake\Http\Response|null
     */
    public function deleteDocument(?string $id = null, ?string $documentId = null): ?Response
    {
        $this->request->allowMethod(['post', 'delete']);
        $doc = $this->NoveltyLiquidationDocs->get($id);

        $documentsTable = $this->fetchTable('NoveltyDocuments');
        $document = $documentsTable->get($documentId);

        if (!$this->documentService->canDeleteDocument($document, $doc->pipeline_status)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'Solo puede eliminar documentos de la etapa actual.']);
            }
            $this->Flash->error('Solo puede eliminar documentos de la etapa actual.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $deleted = $this->documentService->deleteDocument((int)$documentId);

        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(
                $deleted
                    ? ['success' => true]
                    : ['success' => false, 'error' => 'No se pudo eliminar el documento.'],
            );
        }

        if ($deleted) {
            $this->Flash->success('Documento eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el documento.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function _liquidationDocumentLabels(): array
    {
        return [NoveltyPresentation::STATUS_BADGES, NoveltyConstants::STATUS_LABELS];
    }

    /**
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null
     */
    public function addObservation(?string $id = null): ?Response
    {
        return $this->_handleAddObservation(
            'NoveltyObservations',
            'liquidation_doc_id',
            $id,
            $this->Authentication->getIdentity()->getOriginalData(),
            fn() => $this->redirect(['action' => 'edit', $id]),
        );
    }
}

<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Constants\NoveltyConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Controller\Trait\ObservationControllerTrait;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Model\Entity\User;
use App\Service\NoveltyDocumentService;
use App\Service\NoveltyHistoryService;
use App\Service\NoveltyObservationService;
use App\Service\NoveltyPipelineService;
use App\Service\NoveltySignatureService;
use App\Service\Pipeline\Novelty\Policy\NoveltyActionPolicy;
use App\View\Presentation\NoveltyPresentation;
use App\ViewModel\NoveltyLiquidationDocEditViewModel;
use App\ViewModel\NoveltyLiquidationDocViewViewModel;
use Cake\Http\Response;
use Cake\Routing\Router;
use DateTime;

class NoveltyLiquidationDocsController extends AppController
{
    use DocumentJsonPayloadTrait;
    use ObservationControllerTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private NoveltyPipelineService $pipelineService;

    private NoveltyDocumentService $documentService;

    private NoveltyObservationService $observationService;

    private NoveltySignatureService $signatureService;

    private NoveltyActionPolicy $actionPolicy;

    private NoveltyHistoryService $historyService;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->pipelineService = $container->get(NoveltyPipelineService::class);
        $this->documentService = $container->get(NoveltyDocumentService::class);
        $this->observationService = $container->get(NoveltyObservationService::class);
        $this->signatureService = $container->get(NoveltySignatureService::class);
        $this->actionPolicy = $container->get(NoveltyActionPolicy::class);
        $this->historyService = $container->get(NoveltyHistoryService::class);
    }

    /**
     * "Mis D. de Liquidación" — filters by the statuses visible to the user's role.
     * Optional ?pipeline_status=... narrows down within that set.
     *
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'view')]
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

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['NoveltyLiquidationDocs.liquidation_number LIKE' => '%' . $search . '%']);
        }

        $liquidationDocs = $this->paginate($query);
        $this->set(compact('liquidationDocs', 'statusFilter', 'visibleStatuses'));
    }

    /**
     * "Todos los D. de Liquidación" — no role filter, optional ?pipeline_status=...
     *
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'view')]
    public function all()
    {
        $query = $this->NoveltyLiquidationDocs->find()
            ->contain(['PerformedByUsers', 'EmployeeNovelties'])
            ->orderBy(['NoveltyLiquidationDocs.created' => 'DESC']);

        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['NoveltyLiquidationDocs.pipeline_status' => $statusFilter]);
        }

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['NoveltyLiquidationDocs.liquidation_number LIKE' => '%' . $search . '%']);
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
    #[Permission(action: 'view')]
    public function rejected()
    {
        $query = $this->NoveltyLiquidationDocs->find()
            ->contain(['PerformedByUsers', 'EmployeeNovelties'])
            ->where(['NoveltyLiquidationDocs.pipeline_status' => NoveltyConstants::STATUS_RECHAZADA])
            ->orderBy(['NoveltyLiquidationDocs.created' => 'DESC']);

        $search = trim((string)$this->request->getQuery('search', ''));
        if ($search !== '') {
            $query->where(['NoveltyLiquidationDocs.liquidation_number LIKE' => '%' . $search . '%']);
        }

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
    #[Permission(action: 'view')]
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

        $this->set('viewModel', new NoveltyLiquidationDocViewViewModel($doc));
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
    #[Permission(action: 'edit')]
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
        $canRegisterPayment = $this->actionPolicy->canRegisterPayment($doc, $roleId);
        $canAuthorizePayment = $this->actionPolicy->canAuthorizePayment($doc, $roleId);
        $canConfirmPayment = $this->actionPolicy->canConfirmPayment($doc, $roleId);

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
    #[Permission(action: 'edit')]
    public function advanceGroup(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $doc = $this->NoveltyLiquidationDocs->get($id);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        // Guardar campos editables del header SOLO si el rol puede operar el paso
        // actual (canOperate), y con whitelist explícita: pipeline_status /
        // payment_status / payment_date / created_by los controla el pipeline y el
        // flujo de pago — NO deben mass-assignarse desde el formulario (SU-sec).
        $data = $this->request->getData();
        $canOperateStep = $this->pipelineService->denialReasonForAdvanceGroup($doc, (int)$user->role_id) === null;
        if ($canOperateStep && !empty($data)) {
            $doc = $this->NoveltyLiquidationDocs->patchEntity($doc, $data, [
                'fields' => ['liquidation_number', 'period', 'document_date', 'performed_by', 'passes_for_payment'],
            ]);
            $this->NoveltyLiquidationDocs->save($doc);
        }

        $result = $this->pipelineService->advanceGroup($doc, (int)$user->role_id, (int)$user->id);

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
    #[Permission(action: 'edit')]
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
    #[Permission(action: 'add')]
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

        if (!is_string($result)) {
            $this->_recordDocumentChange((int)$doc->id, null, $result->file_name, (int)$user->id);
        }

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
    #[Permission(action: 'add')]
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

        if (!is_string($result)) {
            $this->_recordDocumentChange((int)$doc->id, null, $result->file_name, (int)$user->id);
        }

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
    #[Permission(action: 'edit')]
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

        $previous = $this->documentService->getLiquidationDocument((int)$doc->id);
        $previousFileName = $previous?->file_name;

        $result = $this->documentService->updateLiquidationDocument($doc->id, $file, $user->id);

        if (!is_string($result)) {
            $this->_recordDocumentChange((int)$doc->id, $previousFileName, $result->file_name, (int)$user->id);
        }

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
    #[Permission(action: 'delete')]
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

        $fileName = $document->file_name;
        $deleted = $this->documentService->deleteDocument((int)$documentId);

        if ($deleted) {
            $userId = (int)$this->Authentication->getIdentity()->getOriginalData()->id;
            $this->_recordDocumentChange((int)$doc->id, $fileName, null, $userId);
        }

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
     * Registra el cambio de documento en el historial de cada novedad agrupada
     * por el documento de liquidación. El audit de novedades se indexa por
     * `novelty_id` (no existe un id de documento de liquidación en la tabla de
     * historiales), y un documento agrupa N novedades sin ancla única, por lo
     * que se registra en todas las novedades hijas del documento.
     *
     * @param int $liquidationDocId Documento de liquidación.
     * @param string|null $oldValue Nombre de archivo previo (null en alta).
     * @param string|null $newValue Nombre de archivo nuevo (null en borrado).
     * @param int $userId Usuario que realizó la operación.
     * @return void
     */
    private function _recordDocumentChange(
        int $liquidationDocId,
        ?string $oldValue,
        ?string $newValue,
        int $userId,
    ): void {
        $noveltyIds = $this->fetchTable('EmployeeNovelties')->find()
            ->select(['id'])
            ->where(['liquidation_doc_id' => $liquidationDocId])
            ->all()
            ->extract('id')
            ->toArray();

        foreach ($noveltyIds as $noveltyId) {
            $this->historyService->recordFieldChange(
                (int)$noveltyId,
                'document',
                $oldValue,
                $newValue,
                $userId,
            );
        }
    }

    /**
     * @param string|null $id Document ID.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
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

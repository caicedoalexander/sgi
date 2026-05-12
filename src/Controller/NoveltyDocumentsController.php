<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Constants\NoveltyConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Service\NoveltyDocumentService;
use App\View\Presentation\NoveltyPresentation;
use Cake\Routing\Router;

class NoveltyDocumentsController extends AppController
{
    use DocumentJsonPayloadTrait;

    private NoveltyDocumentService $documentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->documentService = $this->getContainer()->get(NoveltyDocumentService::class);
    }

    #[Permission(action: 'edit')]
    public function upload(?string $noveltyId = null)
    {
        $this->request->allowMethod(['post']);
        $noveltiesTable = $this->fetchTable('EmployeeNovelties');
        $novelty = $noveltiesTable->get($noveltyId);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $file = $this->request->getUploadedFile('file');

        if (!$file) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'No se seleccionó ningún archivo.']);
            }
            $this->Flash->error('No se seleccionó ningún archivo.');

            return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
        }

        $result = $this->documentService->uploadForNovelty($novelty->id, $novelty->pipeline_status, $file, $user->id);

        if ($this->_isJsonRequest()) {
            if (is_string($result)) {
                return $this->_jsonResponse(['success' => false, 'error' => $result]);
            }

            $canDelete = $this->documentService->canDeleteDocument($result, $novelty->pipeline_status);
            [$badgeColors, $statusLabels] = $this->_noveltyDocumentLabels();
            $deleteUrl = $canDelete
                ? Router::url(['controller' => 'NoveltyDocuments', 'action' => 'delete', $novelty->id, $result->id])
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

        return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
    }

    #[Permission(action: 'delete')]
    public function delete(?string $noveltyId = null, ?string $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $noveltiesTable = $this->fetchTable('EmployeeNovelties');
        $novelty = $noveltiesTable->get($noveltyId);

        $documentsTable = $this->fetchTable('NoveltyDocuments');
        $document = $documentsTable->get($documentId);

        if (!$this->documentService->canDeleteDocument($document, $novelty->pipeline_status)) {
            if ($this->_isJsonRequest()) {
                return $this->_jsonResponse(['success' => false, 'error' => 'Solo puede eliminar documentos de la etapa actual.']);
            }
            $this->Flash->error('Solo puede eliminar documentos de la etapa actual.');

            return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
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

        return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
    }

    /**
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function _noveltyDocumentLabels(): array
    {
        return [NoveltyPresentation::STATUS_BADGES, NoveltyConstants::STATUS_LABELS];
    }
}

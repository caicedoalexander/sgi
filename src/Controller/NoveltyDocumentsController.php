<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\PipelineAction;
use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Controller\Trait\DocumentJsonPayloadTrait;
use App\Model\Entity\EmployeeNovelty;
use App\Service\NoveltyDocumentService;
use App\Service\Pipeline\Novelty\Policy\NoveltyActionPolicy;
use App\View\Presentation\NoveltyPresentation;
use Cake\Http\Response;
use Cake\Routing\Router;

class NoveltyDocumentsController extends AppController
{
    use DocumentJsonPayloadTrait;

    private NoveltyDocumentService $documentService;

    private NoveltyActionPolicy $actionPolicy;

    /**
     * Configura componentes y servicios del controlador.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->documentService = $container->get(NoveltyDocumentService::class);
        $this->actionPolicy = $container->get(NoveltyActionPolicy::class);
    }

    /**
     * Sube un documento de novedad.
     *
     * @param string|null $noveltyId ID de la novedad.
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_NOVELTIES)]
    public function upload(?string $noveltyId = null)
    {
        $this->request->allowMethod(['post']);
        $noveltiesTable = $this->fetchTable('EmployeeNovelties');
        $novelty = $noveltiesTable->get($noveltyId);

        $gate = $this->_documentGate($novelty, 'subir');
        if ($gate !== null) {
            return $gate;
        }

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

    /**
     * Elimina un documento de novedad.
     *
     * @param string|null $noveltyId ID de la novedad.
     * @param string|null $documentId ID del documento.
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_NOVELTIES)]
    public function delete(?string $noveltyId = null, ?string $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $noveltiesTable = $this->fetchTable('EmployeeNovelties');
        $novelty = $noveltiesTable->get($noveltyId);

        $gate = $this->_documentGate($novelty, 'eliminar');
        if ($gate !== null) {
            return $gate;
        }

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
     * Gate compartido de soportes: 409 si la novedad está pagada o rechazada, 403
     * si el rol no puede operar el paso actual del pipeline de novedades.
     */
    private function _documentGate(EmployeeNovelty $novelty, string $blockedActionLabel): ?Response
    {
        if ($novelty->isPaid() || $novelty->isRejected()) {
            return $this->_documentGateError(
                sprintf('No se puede %s un soporte de una novedad cerrada.', $blockedActionLabel),
                (int)$novelty->id,
                409,
            );
        }

        $roleId = (int)$this->Authentication->getIdentity()->getOriginalData()->role_id;
        if (!$this->actionPolicy->canOperateStep($roleId, (string)$novelty->pipeline_status)) {
            return $this->_documentGateError(
                'No tiene permisos para gestionar soportes en este paso.',
                (int)$novelty->id,
                403,
            );
        }

        return null;
    }

    /**
     * Construye la respuesta de error del gate de documentos. JSON con status
     * HTTP apropiado para AJAX, redirect con flash para POST tradicional.
     */
    private function _documentGateError(string $message, int $noveltyId, int $statusCode): Response
    {
        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(['success' => false, 'error' => $message], $statusCode);
        }

        $this->Flash->error($message);

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

<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthorizationService;
use App\Service\NoveltyDocumentService;
use App\Service\SidebarCounterService;
use Cake\Controller\ComponentRegistry;
use Cake\Event\EventManagerInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;

class NoveltyDocumentsController extends AppController
{
    /**
     * @param \App\Service\NoveltyDocumentService $documentService Document service.
     * @param \App\Service\AuthorizationService $authService Authorization service.
     * @param \App\Service\SidebarCounterService $counterService Sidebar counters.
     * @param \Cake\Http\ServerRequest|null $request Request.
     * @param \Cake\Http\Response|null $response Response.
     * @param string|null $name Controller name.
     * @param \Cake\Event\EventManagerInterface|null $eventManager Event manager.
     * @param \Cake\Controller\ComponentRegistry|null $components Component registry.
     */
    public function __construct(
        private readonly NoveltyDocumentService $documentService,
        AuthorizationService $authService,
        SidebarCounterService $counterService,
        ?ServerRequest $request = null,
        ?Response $response = null,
        ?string $name = null,
        ?EventManagerInterface $eventManager = null,
        ?ComponentRegistry $components = null,
    ) {
        parent::__construct($authService, $counterService, $request, $response, $name, $eventManager, $components);
    }

    public function upload(?string $noveltyId = null)
    {
        $this->request->allowMethod(['post']);
        $noveltiesTable = $this->fetchTable('EmployeeNovelties');
        $novelty = $noveltiesTable->get($noveltyId);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $file = $this->request->getUploadedFile('document');

        if (!$file) {
            $this->Flash->error('No se seleccionó ningún archivo.');

            return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
        }

        $result = $this->documentService->uploadForNovelty($novelty->id, $novelty->pipeline_status, $file, $user->id);

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Documento subido exitosamente.');
        }

        return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
    }

    public function delete(?string $noveltyId = null, ?string $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $noveltiesTable = $this->fetchTable('EmployeeNovelties');
        $novelty = $noveltiesTable->get($noveltyId);

        $documentsTable = $this->fetchTable('NoveltyDocuments');
        $document = $documentsTable->get($documentId);

        if (!$this->documentService->canDeleteDocument($document, $novelty->pipeline_status)) {
            $this->Flash->error('Solo puede eliminar documentos de la etapa actual.');

            return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
        }

        if ($this->documentService->deleteDocument((int)$documentId)) {
            $this->Flash->success('Documento eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el documento.');
        }

        return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
    }
}

<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthorizationService;
use App\Service\DianCrosscheckService;
use App\Service\SidebarCounterService;
use Cake\Controller\ComponentRegistry;
use Cake\Event\EventManagerInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;

class DianCrosschecksController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * @param \App\Service\DianCrosscheckService $crosscheckService Crosscheck service.
     * @param \App\Service\AuthorizationService $authService Authorization service.
     * @param \App\Service\SidebarCounterService $counterService Sidebar counters.
     * @param \Cake\Http\ServerRequest|null $request Request.
     * @param \Cake\Http\Response|null $response Response.
     * @param string|null $name Controller name.
     * @param \Cake\Event\EventManagerInterface|null $eventManager Event manager.
     * @param \Cake\Controller\ComponentRegistry|null $components Component registry.
     */
    public function __construct(
        private readonly DianCrosscheckService $crosscheckService,
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

    /**
     * List DIAN crosscheck records.
     *
     * @return void
     */
    public function index(): void
    {
        $query = $this->DianCrosschecks->find()
            ->contain(['UploadedByUsers'])
            ->order(['DianCrosschecks.created' => 'DESC']);

        $statusFilter = $this->request->getQuery('status');
        if ($statusFilter) {
            $query->where(['DianCrosschecks.status' => $statusFilter]);
        }

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $dianCrosschecks = $this->paginate($query);

        $this->set(compact('dianCrosschecks', 'statusFilter'));
    }

    /**
     * Upload a new DIAN crosscheck file.
     *
     * @return \Cake\Http\Response|null
     */
    public function add(): ?Response
    {
        if ($this->request->is('post')) {
            $file = $this->request->getUploadedFile('excel_file');

            if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
                $this->Flash->error('Debe seleccionar un archivo Excel válido.');

                return $this->redirect(['action' => 'add']);
            }

            $user = $this->Authentication->getIdentity();
            $result = $this->crosscheckService->processUpload($file, (int)$user->getIdentifier());

            if (!$result->success) {
                $this->Flash->error($result->firstError());

                return $this->redirect(['action' => 'add']);
            }

            $this->Flash->success('Archivo enviado para cruce DIAN.');

            return $this->redirect(['action' => 'index']);
        }

        return null;
    }
}

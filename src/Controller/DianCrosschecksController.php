<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Service\DianCrosscheckService;
use Cake\Http\Response;

class DianCrosschecksController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private DianCrosscheckService $crosscheckService;

    /**
     * Configura componentes y servicios del controlador.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->crosscheckService = $this->getContainer()->get(DianCrosscheckService::class);
    }

    /**
     * List DIAN crosscheck records.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index(): void
    {
        $query = $this->DianCrosschecks->find()
            ->contain(['UploadedByUsers'])
            ->orderBy(['DianCrosschecks.created' => 'DESC']);

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
    #[Permission(action: 'add')]
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

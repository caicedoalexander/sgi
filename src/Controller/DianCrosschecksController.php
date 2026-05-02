<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\DianCrosscheckService;

class DianCrosschecksController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private DianCrosscheckService $crosscheckService;

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

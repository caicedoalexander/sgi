<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ExcelService;

class ProvidersController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private ExcelService $excelService;

    public function initialize(): void
    {
        parent::initialize();
        $this->excelService = new ExcelService();
    }

    public function index()
    {
        $providers = $this->paginate($this->Providers);

        $this->set(compact('providers'));
    }

    public function view($id = null)
    {
        $provider = $this->Providers->get($id, contain: ['Invoices']);

        $this->set(compact('provider'));
    }

    public function add()
    {
        $provider = $this->Providers->newEmptyEntity();
        if ($this->request->is('post')) {
            $provider = $this->Providers->patchEntity($provider, $this->request->getData());
            if ($this->Providers->save($provider)) {
                $this->Flash->success(__('El proveedor ha sido guardado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar el proveedor. Intente de nuevo.'));
        }

        $this->set(compact('provider'));
    }

    public function edit($id = null)
    {
        $provider = $this->Providers->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $provider = $this->Providers->patchEntity($provider, $this->request->getData());
            if ($this->Providers->save($provider)) {
                $this->Flash->success(__('El proveedor ha sido actualizado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar el proveedor. Intente de nuevo.'));
        }

        $this->set(compact('provider'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $provider = $this->Providers->get($id);
        if ($this->Providers->delete($provider)) {
            $this->Flash->success(__('El proveedor ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el proveedor. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function export()
    {
        $query = $this->Providers->find()
            ->select(['Providers.document_type', 'Providers.document_number', 'Providers.name', 'Providers.active'])
            ->order(['Providers.name' => 'ASC']);

        $filePath = $this->excelService->exportCatalog('Proveedores', $query);

        $response = $this->response->withFile($filePath, [
            'download' => true,
            'name' => 'proveedores_' . date('Y-m-d') . '.xlsx',
        ]);

        register_shutdown_function(function () use ($filePath) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        });

        return $response;
    }

    public function import()
    {
        $this->request->allowMethod(['post']);

        $file = $this->request->getUploadedFile('excel_file');
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error('No se recibió un archivo válido.');

            return $this->redirect(['action' => 'index']);
        }

        $result = $this->excelService->importCatalog('Providers', $file, 'document_number');

        $this->Flash->success($result->getSummary());

        foreach ($result->errors as $error) {
            $this->Flash->warning($error);
        }

        return $this->redirect(['action' => 'index']);
    }
}

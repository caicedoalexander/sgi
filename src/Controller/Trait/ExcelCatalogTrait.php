<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Service\ExcelService;

trait ExcelCatalogTrait
{
    public function export()
    {
        $tableName = $this->fetchTable()->getTable();
        $modelName = $this->fetchTable()->getAlias();

        $excelService = new ExcelService();
        $query = $this->fetchTable()->find();
        $filePath = $excelService->exportCatalog($modelName, $query);

        $response = $this->response->withFile($filePath, [
            'download' => true,
            'name' => $tableName . '_' . date('Y-m-d') . '.xlsx',
        ]);

        // Clean up temp file after response
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

        $modelName = $this->fetchTable()->getAlias();
        $excelService = new ExcelService();
        $result = $excelService->importCatalog($modelName, $file);

        $this->Flash->success($result->getSummary());

        foreach ($result->errors as $error) {
            $this->Flash->warning($error);
        }

        return $this->redirect(['action' => 'index']);
    }
}

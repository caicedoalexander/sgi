<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Model\Excel\ExcelExportableInterface;
use App\Service\ExcelImportService;
use App\Service\ExcelMappingService;
use App\Service\ExcelService;
use ArrayObject;
use Cake\Http\Response;
use Exception;
use LogicException;

/**
 * HTTP wizard for Excel export/import. The controller's primary Table
 * (returned by fetchTable()) MUST implement ExcelExportableInterface.
 *
 * Endpoints:
 *   GET  /<controller>/export-config   → JSON exportable fields
 *   POST /<controller>/export          → XLSX download (selected fields)
 *   POST /<controller>/import-upload   → JSON tempName + auto-mapping
 *   POST /<controller>/import-process  → JSON ImportResult summary
 */
trait ExcelWizardTrait
{
    /**
     * Resolve the primary Table and ensure it implements the contract.
     *
     * @return \App\Model\Excel\ExcelExportableInterface
     */
    private function _excelTable(): ExcelExportableInterface
    {
        $table = $this->fetchTable();
        if (!$table instanceof ExcelExportableInterface) {
            throw new LogicException(sprintf(
                '%s must implement %s to use ExcelWizardTrait.',
                $table::class,
                ExcelExportableInterface::class,
            ));
        }

        return $table;
    }

    /**
     * Return JSON list of exportable fields.
     *
     * @return void
     */
    public function exportConfig(): void
    {
        $this->request->allowMethod(['get']);
        $this->viewBuilder()->setClassName('Json');

        $fields = (new ExcelMappingService())->getExportableFields($this->_excelTable());

        $this->set('fields', $fields);
        $this->viewBuilder()->setOption('serialize', ['fields']);
    }

    /**
     * Stream an XLSX download with the user-selected fields.
     *
     * @return \Cake\Http\Response|null
     */
    public function export(): ?Response
    {
        $this->request->allowMethod(['post']);

        $table = $this->_excelTable();
        $mapping = new ExcelMappingService();
        $allDefinitions = $table->getExcelFields();

        $requestFields = $this->request->getData('fields');
        if (empty($requestFields) || !is_array($requestFields)) {
            return $this->_excelJsonError(400, 'No se seleccionaron campos para exportar.');
        }

        $validFields = array_values(array_filter($requestFields, fn($f) => isset($allDefinitions[$f])));
        if (empty($validFields)) {
            return $this->_excelJsonError(400, 'Ningún campo válido seleccionado.');
        }

        $query = $table->find();
        $contains = $table->getExcelExportContains();
        if (!empty($contains)) {
            $query->contain($contains);
        }

        $query->formatResults(function ($results) use ($validFields, $allDefinitions) {
            return $results->map(function ($entity) use ($validFields, $allDefinitions) {
                $data = [];
                foreach ($validFields as $field) {
                    $def = $allDefinitions[$field];
                    if (!empty($def['display_only'])) {
                        $rel = $def['fk_target'] ?? null;
                        if ($rel && isset($def['fk_resolve'])) {
                            // The display_only field's name (e.g. 'position') is also
                            // the association alias (lowerCamel). Resolve via that path.
                            $assoc = $field;
                            $related = $entity->{$assoc} ?? null;
                            $data[$field] = $related ? ($related->{$def['fk_resolve']} ?? '') : '';
                        } else {
                            $data[$field] = '';
                        }
                    } elseif (!empty($def['fk']) && !empty($def['fk_code'])) {
                        $assoc = preg_replace('/_id$/', '', $field);
                        $related = $entity->{$assoc} ?? null;
                        $data[$field] = $related ? ($related->{$def['fk_code']} ?? '') : '';
                    } else {
                        $data[$field] = $entity->{$field} ?? '';
                    }
                }

                return new ArrayObject($data);
            });
        });

        $excelService = new ExcelService();
        $filePath = $excelService->exportWithLabels(
            $table->getExcelSheetTitle(),
            $query,
            $validFields,
            $mapping->getLabelMap($table),
        );

        $response = $this->response->withFile($filePath, [
            'download' => true,
            'name' => $table->getExcelDownloadSlug() . '_' . date('Y-m-d') . '.xlsx',
        ]);

        register_shutdown_function(function () use ($filePath): void {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        });

        return $response;
    }

    /**
     * Receive an uploaded XLSX, persist as temp, return headers + auto-mapping.
     *
     * @return void
     */
    public function importUpload(): void
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $table = $this->_excelTable();
        if (!$table->isExcelImportable()) {
            $this->response = $this->response->withStatus(405);
            $this->set('error', 'Importación no permitida en este módulo.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        $file = $this->request->getUploadedFile('excel_file');
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->response = $this->response->withStatus(400);
            $this->set('error', 'No se recibió un archivo válido.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        $tempName = 'sgi_import_' . bin2hex(random_bytes(8));
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempName . '.xlsx';
        $file->moveTo($tempPath);

        $importService = new ExcelImportService();
        $mapping = new ExcelMappingService();

        try {
            $headers = $importService->readHeaders($tempPath);
            $autoMapping = $mapping->autoMapColumns($headers, $table);
            $systemFields = $mapping->getImportableFields($table);

            $this->set(compact('tempName', 'headers', 'autoMapping', 'systemFields'));
            $this->viewBuilder()->setOption('serialize', ['tempName', 'headers', 'autoMapping', 'systemFields']);
        } catch (Exception $e) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            $this->response = $this->response->withStatus(400);
            $this->set('error', $e->getMessage());
            $this->viewBuilder()->setOption('serialize', ['error']);
        }
    }

    /**
     * Process a previously uploaded file using the user-confirmed mapping.
     *
     * @return void
     */
    public function importProcess(): void
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $table = $this->_excelTable();
        if (!$table->isExcelImportable()) {
            $this->response = $this->response->withStatus(405);
            $this->set('error', 'Importación no permitida en este módulo.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        $tempName = $this->request->getData('temp_file');
        $mappingData = $this->request->getData('mapping');
        $enabledHeaders = $this->request->getData('enabled');

        if (!$tempName || !$mappingData || !$enabledHeaders) {
            $this->response = $this->response->withStatus(400);
            $this->set('error', 'Datos de importación incompletos.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        if (!preg_match('/^sgi_import_[a-f0-9]{16}$/', $tempName)) {
            $this->response = $this->response->withStatus(400);
            $this->set('error', 'Referencia de archivo inválida.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempName . '.xlsx';
        if (!file_exists($tempPath)) {
            $this->response = $this->response->withStatus(400);
            $this->set('error', 'El archivo temporal ha expirado. Por favor, suba el archivo nuevamente.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        try {
            $userId = (int)$this->request->getAttribute('identity')->getIdentifier();
            $importService = new ExcelImportService();
            $result = $importService->processImport($tempPath, $table, $mappingData, $enabledHeaders, $userId);

            $this->set([
                'success' => empty($result->errors) || $result->created > 0 || $result->updated > 0,
                'created' => $result->created,
                'updated' => $result->updated,
                'unchanged' => $result->unchanged,
                'skipped' => $result->skipped,
                'errors' => $result->errors,
                'summary' => $result->getSummary(),
            ]);
            $this->viewBuilder()->setOption('serialize', [
                'success', 'created', 'updated', 'unchanged', 'skipped', 'errors', 'summary',
            ]);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Send a JSON error response.
     *
     * @param int $status HTTP status code
     * @param string $message Error message
     * @return \Cake\Http\Response|null
     */
    private function _excelJsonError(int $status, string $message): ?Response
    {
        $this->response = $this->response->withStatus($status);
        $this->viewBuilder()->setClassName('Json');
        $this->set('error', $message);
        $this->viewBuilder()->setOption('serialize', ['error']);

        return null;
    }
}

<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\NoveltyConstants;
use App\Service\EmployeeDocumentService;
use App\Service\EmployeeFilterService;
use App\Service\EmployeeHistoryService;
use App\Service\ExcelImportService;
use App\Service\ExcelMappingService;
use App\Service\ExcelService;
use ArrayObject;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;
use Exception;

class EmployeesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private EmployeeFilterService $filterService;
    private EmployeeDocumentService $documentService;
    private EmployeeHistoryService $historyService;

    public function initialize(): void
    {
        parent::initialize();
        $this->filterService = new EmployeeFilterService();
        $this->documentService = new EmployeeDocumentService();
        $this->historyService = new EmployeeHistoryService();
    }

    /**
     * Return exportable fields as JSON for the export modal.
     */
    public function exportConfig(): void
    {
        $this->request->allowMethod(['get']);
        $this->viewBuilder()->setClassName('Json');

        $mappingService = new ExcelMappingService();
        $fields = $mappingService->getExportableFields('Employees');

        $this->set('fields', $fields);
        $this->viewBuilder()->setOption('serialize', ['fields']);
    }

    public function index()
    {
        $query = $this->Employees->find('withCurrentNovelty')
            ->contain(['EmployeeStatuses', 'Positions', 'OperationCenters'])
            ->order(['Employees.last_name1' => 'ASC', 'Employees.last_name2' => 'ASC']);

        $this->filterService->apply($query, $this->request->getQueryParams());

        $employees = $this->paginate($query);

        $positions = $this->Employees->Positions->find('codeList')->all();
        $operationCenters = $this->Employees->OperationCenters->find('codeList')->all();
        $employeeStatuses = $this->Employees->EmployeeStatuses->find('list')->all();

        $this->set(compact('employees', 'positions', 'operationCenters', 'employeeStatuses'));
    }

    public function view($id = null)
    {
        $employee = $this->Employees->get($id, contain: [
            'EmployeeStatuses',
            'MaritalStatuses',
            'EducationLevels',
            'Positions',
            'SupervisorPositions',
            'OperationCenters',
            'CostCenters',
            'TemporaryOrganizations',
            'EmployeeNovelties' => [
                'sort' => ['EmployeeNovelties.created' => 'DESC'],
                'NoveltyTypes',
                'RegisteredByUsers',
            ],
            'EmployeeFolders' => [
                'sort' => ['EmployeeFolders.name' => 'ASC'],
                'EmployeeDocuments' => [
                    'sort' => ['EmployeeDocuments.name' => 'ASC'],
                    'UploadedByUsers',
                ],
            ],
            'EmployeeObservations' => [
                'sort' => ['EmployeeObservations.created' => 'ASC'],
                'Users',
            ],
            'EmployeeHistories' => [
                'sort' => ['EmployeeHistories.created' => 'DESC'],
                'Users',
            ],
        ]);

        $folders = $this->Employees->EmployeeFolders->find()
            ->where(['employee_id' => $id, 'parent_id IS' => null])
            ->contain(['EmployeeDocuments' => ['UploadedByUsers'], 'ChildFolders' => ['EmployeeDocuments' => ['UploadedByUsers']]])
            ->order(['EmployeeFolders.name' => 'ASC'])
            ->all();

        // Current active novelty for today
        $today = date('Y-m-d');
        $currentNovelty = $this->Employees->EmployeeNovelties->find()
            ->where([
                'EmployeeNovelties.employee_id' => $id,
                'EmployeeNovelties.pipeline_status !=' => NoveltyConstants::STATUS_RECHAZADA,
                'OR' => [
                    ['EmployeeNovelties.permission_date' => $today, 'EmployeeNovelties.start_date IS' => null],
                    ['EmployeeNovelties.start_date <=' => $today, 'EmployeeNovelties.end_date >=' => $today],
                ],
            ])
            ->contain(['NoveltyTypes'])
            ->order(['EmployeeNovelties.created' => 'DESC'])
            ->first();

        $this->set(compact('employee', 'folders', 'currentNovelty'));
        $this->set('fieldLabels', EmployeeHistoryService::FIELD_LABELS);
    }

    public function add()
    {
        $employee = $this->Employees->newEmptyEntity();
        if ($this->request->is('post')) {
            $employee = $this->Employees->patchEntity($employee, $this->request->getData());
            if ($this->Employees->save($employee)) {
                $warning = $this->documentService->handleProfileImage(
                    $employee,
                    $this->request->getUploadedFile('profile_image_file'),
                );
                if ($warning) {
                    $this->Flash->warning(__($warning));
                }
                $this->documentService->createDefaultFolders($employee->id);
                $this->Flash->success(__('El empleado ha sido guardado.'));

                return $this->redirect(['action' => 'view', $employee->id]);
            }
            $this->Flash->error(__('No se pudo guardar el empleado. Intente de nuevo.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('employee'));
    }

    public function edit($id = null)
    {
        $employee = $this->Employees->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $original = clone $employee;
            $employee = $this->Employees->patchEntity($employee, $this->request->getData());
            if ($this->Employees->save($employee)) {
                $userId = (int)$this->Authentication->getIdentity()->getIdentifier();
                $this->historyService->recordChanges($original, $employee, $userId);

                $warning = $this->documentService->handleProfileImage(
                    $employee,
                    $this->request->getUploadedFile('profile_image_file'),
                );
                if ($warning) {
                    $this->Flash->warning(__($warning));
                }
                $this->Flash->success(__('El empleado ha sido actualizado.'));

                return $this->redirect(['action' => 'view', $employee->id]);
            }
            $this->Flash->error(__('No se pudo actualizar el empleado. Intente de nuevo.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('employee'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $employee = $this->Employees->get($id);
        $this->documentService->deleteEmployeeFiles($employee->id);
        if ($this->Employees->delete($employee)) {
            $this->Flash->success(__('El empleado ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el empleado. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function addObservation($id = null)
    {
        $this->request->allowMethod(['post']);
        $userId = (int)$this->Authentication->getIdentity()->getIdentifier();

        $observationsTable = $this->fetchTable('EmployeeObservations');
        $observation = $observationsTable->newEntity([
            'employee_id' => $id,
            'user_id' => $userId,
            'message' => $this->request->getData('message'),
        ]);

        if ($observationsTable->save($observation)) {
            $this->Flash->success('Observación agregada.');
        } else {
            $this->Flash->error('No se pudo agregar la observación.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function addFolder($employeeId = null)
    {
        $this->request->allowMethod(['post']);
        $employee = $this->Employees->get($employeeId);

        $foldersTable = TableRegistry::getTableLocator()->get('EmployeeFolders');
        $folder = $foldersTable->newEntity([
            'employee_id' => $employee->id,
            'name' => $this->request->getData('name'),
            'parent_id' => $this->request->getData('parent_id') ?: null,
        ]);

        if ($foldersTable->save($folder)) {
            $this->Flash->success(__('La carpeta ha sido creada.'));
        } else {
            $this->Flash->error(__('No se pudo crear la carpeta.'));
        }

        return $this->redirect(['action' => 'view', $employeeId]);
    }

    public function uploadDocument($employeeId = null)
    {
        $this->request->allowMethod(['post']);
        $this->Employees->get($employeeId);

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            $this->Flash->error(__('No se recibió ningún archivo válido.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        $identity = $this->Authentication->getIdentity();
        $result = $this->documentService->uploadDocument(
            (int)$employeeId,
            (int)$this->request->getData('employee_folder_id'),
            $file,
            $identity ? (int)$identity->getIdentifier() : null,
        );

        if (is_string($result)) {
            $this->Flash->error(__($result));
        } else {
            $this->Flash->success(__('El documento ha sido subido.'));
        }

        return $this->redirect(['action' => 'view', $employeeId]);
    }

    public function deleteDocument($employeeId = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $this->Employees->get($employeeId);

        if ($this->documentService->deleteDocument((int)$documentId)) {
            $this->Flash->success(__('El documento ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el documento.'));
        }

        return $this->redirect(['action' => 'view', $employeeId]);
    }

    /**
     * Export employees to XLSX with user-selected fields via AJAX POST.
     *
     * @return \Cake\Http\Response|null
     */
    public function export(): ?Response
    {
        $this->request->allowMethod(['post']);

        $requestFields = $this->request->getData('fields');
        if (empty($requestFields) || !is_array($requestFields)) {
            $this->response = $this->response->withStatus(400);
            $this->viewBuilder()->setClassName('Json');
            $this->set('error', 'No se seleccionaron campos para exportar.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return null;
        }

        $mappingService = new ExcelMappingService();
        $labelMap = $mappingService->getLabelMap('Employees');

        // Filter to only valid fields
        $allDefinitions = $mappingService->getFieldDefinitions('Employees');
        $validFields = array_filter($requestFields, fn($f) => isset($allDefinitions[$f]));
        if (empty($validFields)) {
            $this->response = $this->response->withStatus(400);
            $this->viewBuilder()->setClassName('Json');
            $this->set('error', 'Ningún campo válido seleccionado.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return null;
        }

        // Re-index to ensure sequential keys
        $validFields = array_values($validFields);

        // Build query with contains for display_only fields
        $query = $this->Employees->find()
            ->contain([
                'EmployeeStatuses',
                'Positions',
                'SupervisorPositions',
                'OperationCenters',
                'CostCenters',
                'MaritalStatuses',
                'EducationLevels',
                'TemporaryOrganizations',
            ])
            ->order(['Employees.last_name1' => 'ASC', 'Employees.last_name2' => 'ASC'])
            ->formatResults(function ($results) use ($validFields, $allDefinitions) {
                return $results->map(function ($employee) use ($validFields, $allDefinitions) {
                    $data = [];
                    // display_only fields: show related entity name
                    $relationNameMap = [
                        'position' => 'position',
                        'supervisor_position' => 'supervisor_position',
                        'operation_center' => 'operation_center',
                        'cost_center' => 'cost_center',
                        'employee_status' => 'employee_status',
                        'marital_status' => 'marital_status',
                        'education_level' => 'education_level',
                        'temporary_organization' => 'temporary_organization',
                    ];
                    // FK fields: show related entity code instead of numeric ID
                    $relationCodeMap = [
                        'position_id' => ['rel' => 'position', 'code' => 'code'],
                        'supervisor_position_id' => ['rel' => 'supervisor_position', 'code' => 'code'],
                        'operation_center_id' => ['rel' => 'operation_center', 'code' => 'code'],
                        'cost_center_id' => ['rel' => 'cost_center', 'code' => 'code'],
                        'employee_status_id' => ['rel' => 'employee_status', 'code' => 'name'],
                        'marital_status_id' => ['rel' => 'marital_status', 'code' => 'name'],
                        'education_level_id' => ['rel' => 'education_level', 'code' => 'name'],
                        'temporary_organization_id' => ['rel' => 'temporary_organization', 'code' => 'nit'],
                    ];

                    foreach ($validFields as $field) {
                        if (isset($relationNameMap[$field])) {
                            $rel = $relationNameMap[$field];
                            $data[$field] = $employee->{$rel}->name ?? '';
                        } elseif (isset($relationCodeMap[$field])) {
                            $info = $relationCodeMap[$field];
                            $related = $employee->{$info['rel']} ?? null;
                            $data[$field] = $related ? ($related->{$info['code']} ?? '') : '';
                        } else {
                            $data[$field] = $employee->{$field} ?? '';
                        }
                    }

                    return new ArrayObject($data);
                });
            });

        $excelService = new ExcelService();
        $filePath = $excelService->exportWithLabels('Empleados', $query, $validFields, $labelMap);

        $response = $this->response->withFile($filePath, [
            'download' => true,
            'name' => 'empleados_' . date('Y-m-d') . '.xlsx',
        ]);

        register_shutdown_function(function () use ($filePath): void {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        });

        return $response;
    }

    /**
     * Upload Excel file, read headers, and return auto-mapping as JSON.
     *
     * @return void
     */
    public function importUpload(): void
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $file = $this->request->getUploadedFile('excel_file');
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->response = $this->response->withStatus(400);
            $this->set('error', 'No se recibió un archivo válido.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        // Save temp file
        $tempName = 'sgi_import_' . bin2hex(random_bytes(8));
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempName . '.xlsx';
        $file->moveTo($tempPath);

        try {
            $importService = new ExcelImportService();
            $headers = $importService->readHeaders($tempPath);

            $mappingService = new ExcelMappingService();
            $autoMapping = $mappingService->autoMapColumns($headers, 'Employees');
            $systemFields = $mappingService->getImportableFields('Employees');

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
     * Process import with user-defined column mapping via AJAX POST.
     *
     * @return void
     */
    public function importProcess(): void
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $tempName = $this->request->getData('temp_file');
        $mapping = $this->request->getData('mapping');
        $enabledHeaders = $this->request->getData('enabled');

        if (!$tempName || !$mapping || !$enabledHeaders) {
            $this->response = $this->response->withStatus(400);
            $this->set('error', 'Datos de importación incompletos.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        // Validate temp file name (prevent path traversal)
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
            $importService = new ExcelImportService();
            $result = $importService->processImport(
                $tempPath,
                'Employees',
                'Employees',
                $mapping,
                $enabledHeaders,
                fn($entity) => $this->documentService->createDefaultFolders((int)$entity->id),
            );

            $this->set([
                'success' => empty($result->errors) || $result->created > 0 || $result->updated > 0,
                'created' => $result->created,
                'updated' => $result->updated,
                'skipped' => $result->skipped,
                'errors' => $result->errors,
                'summary' => $result->getSummary(),
            ]);
            $this->viewBuilder()->setOption('serialize', [
                'success', 'created', 'updated', 'skipped', 'errors', 'summary',
            ]);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    protected function _setFormDropdowns(): void
    {
        $employeeStatuses = $this->Employees->EmployeeStatuses->find('list')->all();
        $maritalStatuses = $this->Employees->MaritalStatuses->find('list')->all();
        $educationLevels = $this->Employees->EducationLevels->find('list')->all();
        $positions = $this->Employees->Positions->find('codeList')->all();
        $operationCenters = $this->Employees->OperationCenters->find('codeList')->all();
        $costCenters = $this->Employees->CostCenters->find('codeList')->all();
        $temporaryOrganizations = $this->Employees->TemporaryOrganizations->find('codeList')
            ->where(['TemporaryOrganizations.active' => true])
            ->all();

        $this->set(compact(
            'employeeStatuses',
            'maritalStatuses',
            'educationLevels',
            'positions',
            'operationCenters',
            'costCenters',
            'temporaryOrganizations',
        ));
    }
}

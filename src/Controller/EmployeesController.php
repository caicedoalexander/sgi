<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\EmployeeStatusConstants;
use App\Service\EmployeeDocumentService;
use App\Service\EmployeeFilterService;
use App\Service\ExcelService;
use ArrayObject;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;

class EmployeesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private EmployeeFilterService $filterService;
    private EmployeeDocumentService $documentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->filterService = new EmployeeFilterService();
        $this->documentService = new EmployeeDocumentService();
    }

    public function index()
    {
        $query = $this->Employees->find()
            ->contain(['EmployeeStatuses', 'Positions', 'OperationCenters'])
            ->order(['Employees.last_name' => 'ASC']);

        $this->filterService->apply($query, $this->request->getQueryParams());

        $employees = $this->paginate($query);

        $positions = $this->Employees->Positions->find('codeList')->all();
        $operationCenters = $this->Employees->OperationCenters->find('codeList')->all();
        $employeeStatuses = $this->Employees->EmployeeStatuses->find('codeList')->all();

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
        ]);

        $folders = $this->Employees->EmployeeFolders->find()
            ->where(['employee_id' => $id, 'parent_id IS' => null])
            ->contain(['EmployeeDocuments' => ['UploadedByUsers'], 'ChildFolders' => ['EmployeeDocuments' => ['UploadedByUsers']]])
            ->order(['EmployeeFolders.name' => 'ASC'])
            ->all();

        $this->set(compact('employee', 'folders'));
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
            $employee = $this->Employees->patchEntity($employee, $this->request->getData());
            if ($this->Employees->save($employee)) {
                // No additional logic needed for retired employees
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

    public function export()
    {
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
            ->order(['Employees.last_name' => 'ASC'])
            ->formatResults(function ($results) {
                return $results->map(function ($employee) {
                    $data = [
                        'document_type' => $employee->document_type,
                        'document_number' => $employee->document_number,
                        'first_name' => $employee->first_name,
                        'last_name' => $employee->last_name,
                        'birth_date' => $employee->birth_date,
                        'gender' => $employee->gender,
                        'email' => $employee->email,
                        'phone' => $employee->phone,
                        'address' => $employee->address,
                        'city' => $employee->city,
                        'hire_date' => $employee->hire_date,
                        'termination_date' => $employee->termination_date,
                        'salary' => $employee->salary,
                        'eps' => $employee->eps,
                        'pension_fund' => $employee->pension_fund,
                        'arl' => $employee->arl,
                        'severance_fund' => $employee->severance_fund,
                        'contract_type' => $employee->contract_type,
                        'temporary_organization' => $employee->temporary_organization->name ?? '',
                        'vest_number' => $employee->vest_number,
                        'notes' => $employee->notes,
                        'active' => $employee->active,
                        'position_id' => $employee->position_id,
                        'position' => $employee->position->name ?? '',
                        'supervisor_position_id' => $employee->supervisor_position_id,
                        'supervisor_position' => $employee->supervisor_position->name ?? '',
                        'operation_center_id' => $employee->operation_center_id,
                        'operation_center' => $employee->operation_center->name ?? '',
                        'cost_center_id' => $employee->cost_center_id,
                        'cost_center' => $employee->cost_center->name ?? '',
                        'employee_status_id' => $employee->employee_status_id,
                        'employee_status' => $employee->employee_status->name ?? '',
                        'marital_status_id' => $employee->marital_status_id,
                        'marital_status' => $employee->marital_status->name ?? '',
                        'education_level_id' => $employee->education_level_id,
                        'education_level' => $employee->education_level->name ?? '',
                    ];

                    return new ArrayObject($data);
                });
            });

        $excelService = new ExcelService();
        $filePath = $excelService->exportCatalog('Empleados', $query);

        $response = $this->response->withFile($filePath, [
            'download' => true,
            'name' => 'empleados_' . date('Y-m-d') . '.xlsx',
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

        $excelService = new ExcelService();
        $result = $excelService->importCatalog(
            'Employees',
            $file,
            'document_number',
            [
                'profile_image',
                'position',
                'supervisor_position',
                'operation_center',
                'cost_center',
                'employee_status',
                'marital_status',
                'education_level',
                'temporary_organization',
            ],
        );

        $this->Flash->success($result->getSummary());

        foreach ($result->errors as $error) {
            $this->Flash->warning($error);
        }

        return $this->redirect(['action' => 'index']);
    }

    protected function _setFormDropdowns(): void
    {
        $employeeStatuses = $this->Employees->EmployeeStatuses->find('codeList')->all();
        $maritalStatuses = $this->Employees->MaritalStatuses->find('codeList')->all();
        $educationLevels = $this->Employees->EducationLevels->find('codeList')->all();
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

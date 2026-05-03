<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\NoveltyConstants;
use App\Controller\Trait\ExcelWizardTrait;
use App\Service\EmployeeDocumentService;
use App\Service\EmployeeFilterService;
use App\Service\EmployeeHistoryService;
use Cake\ORM\TableRegistry;

class EmployeesController extends AppController
{
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private EmployeeFilterService $filterService;

    private EmployeeDocumentService $documentService;

    private EmployeeHistoryService $historyService;

    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->filterService = $container->get(EmployeeFilterService::class);
        $this->documentService = $container->get(EmployeeDocumentService::class);
        $this->historyService = $container->get(EmployeeHistoryService::class);
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
        $user = $this->fetchTable('Users')->get($userId);
        $message = trim((string)$this->request->getData('message'));

        $observationsTable = $this->fetchTable('EmployeeObservations');
        $observation = $observationsTable->newEntity([
            'employee_id' => $id,
            'user_id' => $userId,
            'message' => $message,
        ]);

        $saved = $message !== '' && $observationsTable->save($observation);

        if ($this->_isJsonRequest()) {
            if (!$saved) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => $message === ''
                        ? 'El mensaje no puede estar vacío.'
                        : 'No se pudo agregar la observación.',
                ]);
            }

            return $this->_jsonResponse([
                'success' => true,
                'observation' => [
                    'id' => $observation->id,
                    'message' => $observation->message,
                    'user_name' => $user->full_name,
                    'created' => $observation->created->format('d/m/Y H:i'),
                ],
            ]);
        }

        if ($saved) {
            $this->Flash->success('Observación agregada.');
        } else {
            $this->Flash->error(
                $message === ''
                    ? 'El mensaje no puede estar vacío.'
                    : 'No se pudo agregar la observación.'
            );
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

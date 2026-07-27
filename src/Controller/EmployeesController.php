<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Constants\EmployeeStatusConstants;
use App\Controller\Trait\ExcelWizardTrait;
use App\Controller\Trait\ObservationControllerTrait;
use App\Service\EmployeeDocumentService;
use App\Service\EmployeeFilterService;
use App\Service\EmployeeHistoryService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use RuntimeException;
use Throwable;

class EmployeesController extends AppController
{
    use ExcelWizardTrait;
    use ObservationControllerTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private EmployeeFilterService $filterService;

    private EmployeeDocumentService $documentService;

    private EmployeeHistoryService $historyService;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->filterService = $container->get(EmployeeFilterService::class);
        $this->documentService = $container->get(EmployeeDocumentService::class);
        $this->historyService = $container->get(EmployeeHistoryService::class);

        $employeesTable = $this->fetchTable('Employees');
        $employeesTable->setDocumentService($this->documentService);
        $employeesTable->setHistoryService($this->historyService);
    }

    /**
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        // El orderBy debe ser IDENTICO al de view()::navQuery para que el primer
        // empleado seleccionado coincida con la primera fila visible del panel lateral.
        $query = $this->Employees->find()
            ->select(['Employees.id'])
            ->orderBy(['Employees.last_name1' => 'ASC', 'Employees.last_name2' => 'ASC']);

        $this->filterService->apply($query, $this->request->getQueryParams());

        $firstId = $query->first()?->id;

        if ($firstId === null) {
            // Distingue "BD vacia" (no hay un solo empleado) de "filtros sin matches".
            $hasAnyEmployee = $this->Employees->exists([]);
            $this->set(compact('hasAnyEmployee'));

            return null; // renderiza templates/Employees/index.php (empty-state)
        }

        return $this->redirect([
            'action' => 'view',
            $firstId,
            '?' => $this->request->getQueryParams() ?: null,
        ]);
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null)
    {
        $employee = $this->Employees->get($id, contain: [
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
            // Observations ASC: chat de lectura cronológica natural.
            // Histories DESC: cambios recientes primero (timeline de auditoría).
            // La divergencia es intencional (CR-023).
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
            ->orderBy(['EmployeeFolders.name' => 'ASC'])
            ->all();

        // ─── Carpeta activa del gestor documental ───────────────────────────
        // El aplanado recorre $folders en el MISMO orden en que la vista pinta el
        // árbol (raíz, sus hijas, siguiente raíz), para que el primer id del array
        // sea siempre el primer ítem pintado.
        $folderIds = [];
        $folderNames = [];
        foreach ($folders as $folder) {
            $folderIds[] = (string)$folder->id;
            $folderNames[(string)$folder->id] = $folder->name;
            foreach ($folder->child_folders as $childFolder) {
                $folderIds[] = (string)$childFolder->id;
                $folderNames[(string)$childFolder->id] = $childFolder->name;
            }
        }

        // El id que llega por ?folder= no se confía: se valida contra las carpetas
        // del empleado y cae a la primera si no coincide. Ambos lados son string
        // porque el query llega como string y los ids son int: con comparación
        // estricta, in_array('7', [7], true) sería false.
        $requestedFolder = $this->request->getQuery('folder');
        $requestedFolder = is_scalar($requestedFolder) ? (string)$requestedFolder : '';
        $selectedFolderId = in_array($requestedFolder, $folderIds, true)
            ? $requestedFolder
            : ($folderIds[0] ?? null);
        $selectedFolderName = $selectedFolderId !== null ? $folderNames[$selectedFolderId] : '';

        // Novedad activa hoy: el getter virtual current_novelty filtra en memoria
        // sobre employee_novelties (CR-007 / CR-009).
        $currentNovelty = $employee->current_novelty;

        // Lista para el navegador izquierdo (master-detail). Aplica los mismos
        // filtros del query string que index() para coherencia con el atajo
        // /employees -> click en empleado -> /employees/view/{id}?search=...
        //
        // El orderBy debe ser IDENTICO al de index() para que la autoseleccion
        // del primer empleado coincida con la primera fila visible aqui.
        $navQuery = $this->Employees->find('withCurrentNovelty')
            ->contain(['Positions', 'OperationCenters'])
            ->orderBy(['Employees.last_name1' => 'ASC', 'Employees.last_name2' => 'ASC']);
        $this->filterService->apply($navQuery, $this->request->getQueryParams());

        $navEmployees = $this->paginate($navQuery, ['scope' => 'nav']);
        $navStatus = $this->request->getQuery('status') ?: EmployeeStatusConstants::ACTIVO;
        $navSearch = (string)$this->request->getQuery('search', '');
        $navPositionId = (string)$this->request->getQuery('position_id', '');
        $navOperationCenterId = (string)$this->request->getQuery('operation_center_id', '');

        // Catalogos para los filtros avanzados del panel lateral.
        $positions = $this->Employees->Positions->find('codeList')->all();
        $operationCenters = $this->Employees->OperationCenters->find('codeList')->all();

        $this->set(compact(
            'employee',
            'folders',
            'selectedFolderId',
            'selectedFolderName',
            'currentNovelty',
            'navEmployees',
            'navStatus',
            'navSearch',
            'navPositionId',
            'navOperationCenterId',
            'positions',
            'operationCenters',
        ));
        $this->set('fieldLabels', EmployeeHistoryService::FIELD_LABELS);
    }

    /**
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'add')]
    public function add()
    {
        $employee = $this->Employees->newEmptyEntity();
        if ($this->request->is('post')) {
            $employee = $this->Employees->patchEntity($employee, $this->request->getData());
            $profileFile = $this->request->getUploadedFile('profile_image_file');

            if ($this->_persistEmployee($employee, $profileFile, isNew: true)) {
                $this->Flash->success(__('El empleado ha sido guardado.'));

                return $this->redirect(['action' => 'view', $employee->id]);
            }

            $this->Flash->error(__('No se pudo guardar el empleado. Intente de nuevo.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('employee'));
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
    {
        $employee = $this->Employees->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $original = clone $employee;
            $employee = $this->Employees->patchEntity($employee, $this->request->getData());
            $profileFile = $this->request->getUploadedFile('profile_image_file');

            if ($this->_persistEmployee($employee, $profileFile, isNew: false, original: $original)) {
                $this->Flash->success(__('El empleado ha sido actualizado.'));

                return $this->redirect(['action' => 'view', $employee->id]);
            }

            $this->Flash->error(__('No se pudo actualizar el empleado. Intente de nuevo.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('employee'));
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $employee = $this->Employees->get($id);
        $employeeId = $employee->id;

        if ($this->Employees->delete($employee)) {
            // Solo limpiar archivos físicos si la fila se borró efectivamente
            $this->documentService->deleteEmployeeFiles($employeeId);
            $this->Flash->success(__('El empleado ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el empleado. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * @param string|null $id
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'edit')]
    public function addObservation(?string $id = null)
    {
        $userId = (int)$this->Authentication->getIdentity()->getIdentifier();
        $user = $this->fetchTable('Users')->get($userId);

        return $this->_handleAddObservation(
            'EmployeeObservations',
            'employee_id',
            $id,
            $user,
            fn() => $this->redirect(['action' => 'view', $id]),
        );
    }

    /**
     * @param string|null $employeeId
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'add')]
    public function addFolder(?string $employeeId = null)
    {
        $this->request->allowMethod(['post']);
        $employee = $this->Employees->get($employeeId);

        $parentId = $this->request->getData('parent_id') ?: null;
        $result = $this->documentService->createFolder(
            (int)$employee->id,
            $this->request->getData('name'),
            $parentId !== null ? (int)$parentId : null,
        );

        if (!$result->success) {
            $this->Flash->error(__($result->firstError() ?? 'No se pudo crear la carpeta.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        $this->Flash->success(__('La carpeta ha sido creada.'));

        return $this->redirect(['action' => 'view', $employeeId, '?' => ['folder' => $result->data->id]]);
    }

    /**
     * @param string|null $employeeId
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'add')]
    public function uploadDocument(?string $employeeId = null)
    {
        $this->request->allowMethod(['post']);
        $this->Employees->get($employeeId);

        $uploaded = $this->request->getUploadedFiles()['file'] ?? [];
        $files = is_array($uploaded) ? $uploaded : [$uploaded];

        $identity = $this->Authentication->getIdentity();
        $folderId = (int)$this->request->getData('employee_folder_id');
        $result = $this->documentService->uploadDocuments(
            (int)$employeeId,
            $folderId,
            $files,
            $identity ? (int)$identity->getIdentifier() : null,
        );

        if (!$result->success) {
            $this->Flash->error(__($result->firstError() ?? 'No se pudo subir el documento.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        $data = $result->data;
        if ($data['uploaded'] >= 1) {
            $this->Flash->success(__n('{0} documento subido.', '{0} documentos subidos.', $data['uploaded'], $data['uploaded']));
        }
        if (!empty($data['failed'])) {
            $lines = array_map(
                fn($f) => $f['name'] . ' (' . $f['error'] . ')',
                $data['failed'],
            );
            $this->Flash->error(__('No se pudieron subir: {0}', implode(', ', $lines)));
        }

        return $this->redirect(['action' => 'view', $employeeId, '?' => ['folder' => $folderId]]);
    }

    /**
     * @param string|null $employeeId
     * @param string|null $documentId
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'delete')]
    public function deleteDocument(?string $employeeId = null, ?string $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $this->Employees->get($employeeId);

        $result = $this->documentService->deleteDocument((int)$employeeId, (int)$documentId);
        if (!$result->success) {
            $this->Flash->error(__($result->firstError() ?? 'No se pudo eliminar el documento.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        $this->Flash->success(__('El documento ha sido eliminado.'));

        return $this->redirect([
            'action' => 'view',
            $employeeId,
            '?' => ['folder' => $result->data['employee_folder_id']],
        ]);
    }

    /**
     * Descargar un documento validando ownership y devolviendo el archivo
     * con headers de Content-Disposition. Documentos nuevos viven fuera de
     * webroot; documentos legacy aún pueden estar bajo webroot/uploads/.
     */
    #[Permission(action: 'view')]
    public function downloadDocument(?string $employeeId = null, ?string $documentId = null): Response
    {
        $this->Employees->get($employeeId);

        try {
            $document = $this->documentService->assertDocumentOwnership(
                (int)$employeeId,
                (int)$documentId,
            );
        } catch (RecordNotFoundException) {
            throw new NotFoundException(__('El documento no existe.'));
        }

        $absolutePath = $this->documentService->resolveStoragePath($document->file_path);

        if (!is_file($absolutePath)) {
            throw new NotFoundException(__('El archivo no se encuentra en el servidor.'));
        }

        return $this->response->withFile($absolutePath, [
            'name' => $document->name,
            'download' => false,
        ]);
    }

    /**
     * Persistir empleado (add o edit) en una transacción que incluye:
     * - save de la entidad
     * - createDefaultFolders (solo en add)
     * - handleProfileImage + re-save si hay imagen
     * - history (solo en edit)
     *
     * Si algo falla, BD revierte y los archivos de imagen ya movidos se limpian.
     */
    private function _persistEmployee(
        object $employee,
        ?object $profileFile,
        bool $isNew,
        ?object $original = null,
    ): bool {
        $movedProfilePath = null;

        try {
            return (bool)$this->Employees->getConnection()->transactional(
                function () use ($employee, $profileFile, $isNew, $original, &$movedProfilePath) {
                    if (!$this->Employees->save($employee)) {
                        return false;
                    }

                    if ($isNew) {
                        $foldersResult = $this->documentService->createDefaultFolders($employee->id);
                        if (!$foldersResult->success) {
                            throw new RuntimeException($foldersResult->firstError() ?? 'Folders failed');
                        }
                    }

                    if ($profileFile && $profileFile->getError() === UPLOAD_ERR_OK) {
                        $imageResult = $this->documentService->handleProfileImage($employee, $profileFile);
                        if (!$imageResult->success) {
                            throw new RuntimeException($imageResult->firstError() ?? 'Image failed');
                        }
                        $movedProfilePath = $employee->profile_image;

                        // Re-save para persistir el path de la imagen
                        if (!$this->Employees->save($employee)) {
                            throw new RuntimeException('No se pudo persistir la imagen de perfil.');
                        }
                    }

                    if (!$isNew && $original !== null) {
                        $userId = (int)$this->Authentication->getIdentity()->getIdentifier();
                        $this->historyService->recordChanges($original, $employee, $userId);
                    }

                    return true;
                },
            );
        } catch (Throwable $e) {
            // Limpiar imagen ya movida a disco si la transacción falló
            if ($movedProfilePath !== null) {
                $this->documentService->cleanupProfileImage($movedProfilePath);
            }

            return false;
        }
    }

    /**
     * @return void
     */
    protected function _setFormDropdowns(): void
    {
        $employeeStatuses = EmployeeStatusConstants::STATUS_LABELS;
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

<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\ORM\TableRegistry;

class EmployeesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function index()
    {
        $query = $this->Employees->find()
            ->contain(['EmployeeStatuses', 'Positions', 'OperationCenters'])
            ->order(['Employees.last_name' => 'ASC']);

        $employees = $this->paginate($query);

        $this->set(compact('employees'));
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
                $this->_handleProfileImage($employee);
                $this->_createDefaultFolders($employee->id);
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
                $this->_handleProfileImage($employee);
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
        $this->_deleteEmployeeFiles($employee->id);
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
        $employee = $this->Employees->get($employeeId);

        $file = $this->request->getUploadedFile('file');
        $folderId = $this->request->getData('employee_folder_id');

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error(__('No se recibió ningún archivo válido.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        // Validate size (10MB max)
        if ($file->getSize() > 10 * 1024 * 1024) {
            $this->Flash->error(__('El archivo excede el tamaño máximo de 10MB.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        // Validate MIME type
        $allowedMimes = [
            'application/pdf',
            'image/jpeg', 'image/png', 'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
        ];
        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, $allowedMimes)) {
            $this->Flash->error(__('Tipo de archivo no permitido.'));

            return $this->redirect(['action' => 'view', $employeeId]);
        }

        // Create upload directory
        $uploadDir = WWW_ROOT . 'uploads' . DS . 'employees' . DS . $employeeId;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Save file with unique name
        $originalName = $file->getClientFilename();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $uniqueName = uniqid('doc_') . '.' . $extension;
        $filePath = $uploadDir . DS . $uniqueName;

        $file->moveTo($filePath);

        // Save document record
        $documentsTable = TableRegistry::getTableLocator()->get('EmployeeDocuments');
        $identity = $this->Authentication->getIdentity();
        $document = $documentsTable->newEntity([
            'employee_folder_id' => $folderId,
            'name' => $originalName,
            'file_path' => 'uploads/employees/' . $employeeId . '/' . $uniqueName,
            'file_size' => $file->getSize(),
            'mime_type' => $mimeType,
            'uploaded_by' => $identity ? $identity->getIdentifier() : null,
        ]);

        if ($documentsTable->save($document)) {
            $this->Flash->success(__('El documento ha sido subido.'));
        } else {
            // Clean up file if DB save fails
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $this->Flash->error(__('No se pudo guardar el documento.'));
        }

        return $this->redirect(['action' => 'view', $employeeId]);
    }

    public function deleteDocument($employeeId = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $this->Employees->get($employeeId);

        $documentsTable = TableRegistry::getTableLocator()->get('EmployeeDocuments');
        $document = $documentsTable->get($documentId);

        // Delete physical file
        $filePath = WWW_ROOT . $document->file_path;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        if ($documentsTable->delete($document)) {
            $this->Flash->success(__('El documento ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el documento.'));
        }

        return $this->redirect(['action' => 'view', $employeeId]);
    }

    protected function _handleProfileImage(object $employee): void
    {
        $file = $this->request->getUploadedFile('profile_image_file');
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return;
        }

        // Validate size (2MB max)
        if ($file->getSize() > 2 * 1024 * 1024) {
            $this->Flash->warning(__('La imagen de perfil excede el tamaño máximo de 2MB.'));
            return;
        }

        // Validate MIME type
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, $allowedMimes)) {
            $this->Flash->warning(__('Tipo de imagen no permitido. Use JPEG, PNG, GIF o WebP.'));
            return;
        }

        $uploadDir = WWW_ROOT . 'uploads' . DS . 'employees' . DS . $employee->id;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $fileName = 'profile.' . $extension;
        $filePath = $uploadDir . DS . $fileName;

        // Remove old profile image if exists
        if ($employee->profile_image) {
            $oldPath = WWW_ROOT . $employee->profile_image;
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        $file->moveTo($filePath);

        $relativePath = 'uploads/employees/' . $employee->id . '/' . $fileName;
        $employee->profile_image = $relativePath;
        $this->Employees->save($employee);
    }

    protected function _createDefaultFolders(int $employeeId): void
    {
        $defaultFoldersTable = TableRegistry::getTableLocator()->get('DefaultFolders');
        $foldersTable = TableRegistry::getTableLocator()->get('EmployeeFolders');

        $defaults = $defaultFoldersTable->find()
            ->order(['sort_order' => 'ASC'])
            ->all();

        foreach ($defaults as $default) {
            $folder = $foldersTable->newEntity([
                'employee_id' => $employeeId,
                'name' => $default->name,
                'parent_id' => null,
            ]);
            $foldersTable->save($folder);
        }
    }

    protected function _deleteEmployeeFiles(int $employeeId): void
    {
        $uploadDir = WWW_ROOT . 'uploads' . DS . 'employees' . DS . $employeeId;
        if (is_dir($uploadDir)) {
            $files = glob($uploadDir . DS . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($uploadDir);
        }
    }

    protected function _setFormDropdowns(): void
    {
        $employeeStatuses = $this->Employees->EmployeeStatuses->find('codeList')->all();
        $maritalStatuses = $this->Employees->MaritalStatuses->find('codeList')->all();
        $educationLevels = $this->Employees->EducationLevels->find('codeList')->all();
        $positions = $this->Employees->Positions->find('codeList')->all();
        $operationCenters = $this->Employees->OperationCenters->find('codeList')->all();
        $costCenters = $this->Employees->CostCenters->find('codeList')->all();

        $this->set(compact(
            'employeeStatuses',
            'maritalStatuses',
            'educationLevels',
            'positions',
            'operationCenters',
            'costCenters'
        ));
    }
}

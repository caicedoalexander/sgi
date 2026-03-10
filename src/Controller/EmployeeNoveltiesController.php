<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\NoveltyConstants;
use App\Service\LeaveDocumentService;
use App\Service\NoveltySignatureService;
use Cake\Http\Response;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use DateTime;

class EmployeeNoveltiesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function index()
    {
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $roleName = $this->_getUserRoleName($user);

        $query = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes', 'RegisteredByUsers'])
            ->order(['EmployeeNovelties.created' => 'DESC']);

        if ($roleName !== 'Administrador') {
            $subordinateIds = $this->_getSubordinateEmployeeIds($user);
            if (!empty($subordinateIds)) {
                $query->where(['EmployeeNovelties.employee_id IN' => $subordinateIds]);
            } else {
                $query->where(['1 = 0']);
            }
        }

        $statusFilter = $this->request->getQuery('status');
        if ($statusFilter) {
            $query->where(['EmployeeNovelties.status' => $statusFilter]);
        }

        $typeFilter = $this->request->getQuery('novelty_type_id');
        if ($typeFilter) {
            $query->where(['EmployeeNovelties.novelty_type_id' => $typeFilter]);
        }

        $novelties = $this->paginate($query);

        $noveltyTypes = $this->EmployeeNovelties->NoveltyTypes->find('list')
            ->order(['name' => 'ASC'])
            ->toArray();

        $this->set(compact('novelties', 'statusFilter', 'typeFilter', 'noveltyTypes'));
    }

    public function view($id = null)
    {
        $novelty = $this->EmployeeNovelties->get($id, contain: [
            'Employees',
            'NoveltyTypes',
            'ApprovedByUsers',
            'RegisteredByUsers',
        ]);

        $user = $this->Authentication->getIdentity()->getOriginalData();
        $canApprove = $this->_canApproveNovelty($user, $novelty);

        $service = new LeaveDocumentService();
        $employee = $novelty->employee;
        $template = $service->resolveTemplate(
            (int)$novelty->novelty_type_id,
            $employee->contract_type ?? null,
            $employee->temporary_organization_id ?? null,
        );
        $hasActiveTemplate = $template && $template->is_active;

        $this->set(compact('novelty', 'canApprove', 'hasActiveTemplate'));
    }

    public function exportPdf($id = null): ?Response
    {
        $this->autoRender = false;

        $novelty = $this->EmployeeNovelties->get($id, contain: [
            'Employees',
            'NoveltyTypes',
        ]);

        $service = new LeaveDocumentService();
        $employee = $novelty->employee;
        $template = $service->resolveTemplate(
            (int)$novelty->novelty_type_id,
            $employee->contract_type ?? null,
            $employee->temporary_organization_id ?? null,
        );

        if (!$template || !$template->is_active) {
            $this->Flash->error('No hay plantilla de documento configurada para el tipo de contrato de este empleado.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $pdfContent = $service->generatePdf((int)$id, (int)$template->id);

        return $this->response
            ->withType('application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="novedad_' . $id . '.pdf"')
            ->withStringBody($pdfContent);
    }

    public function add()
    {
        $novelty = $this->EmployeeNovelties->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->Authentication->getIdentity()->getOriginalData();
            $data = $this->request->getData();
            $data['registered_by'] = $user->id;
            $data['status'] = NoveltyConstants::STATUS_PENDING;
            $data['filing_date'] = Date::now()->format('Y-m-d');

            $novelty = $this->EmployeeNovelties->patchEntity($novelty, $data);
            if ($this->EmployeeNovelties->save($novelty)) {
                $signatureService = new NoveltySignatureService();
                $signaturePath = null;

                $signatureFile = $this->request->getUploadedFile('signature_file');
                if ($signatureFile && $signatureFile->getError() === UPLOAD_ERR_OK) {
                    $signaturePath = $signatureService->saveFromUpload($novelty->id, $signatureFile, $user->id, 'employee');
                }

                if (!$signaturePath) {
                    $signatureBase64 = $this->request->getData('signature_base64');
                    if (!empty($signatureBase64)) {
                        $signaturePath = $signatureService->saveFromBase64($novelty->id, $signatureBase64, $user->id, 'employee');
                    }
                }

                if ($signaturePath) {
                    $novelty->employee_signature = $signaturePath;
                    $this->EmployeeNovelties->save($novelty);
                }

                $this->Flash->success(__('La novedad ha sido registrada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo registrar la novedad. Intente de nuevo.'));
        }

        $employees = $this->EmployeeNovelties->Employees->find('list', [
            'keyField' => 'id',
            'valueField' => 'full_name',
        ])->all();

        $noveltyTypes = $this->_getNoveltyTypesGrouped();

        $preselectedEmployee = $this->request->getQuery('employee_id');

        $this->set(compact('novelty', 'employees', 'noveltyTypes', 'preselectedEmployee'));
    }

    public function approve($id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['Employees']);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        if (!$this->_canApproveNovelty($user, $novelty)) {
            $this->Flash->error('No tiene permisos para aprobar esta novedad.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $novelty->status = NoveltyConstants::STATUS_APPROVED;
        $novelty->approved_by = $user->id;
        $novelty->approved_at = new DateTime();

        $signatureService = new NoveltySignatureService();
        $coordSignatureFile = $this->request->getUploadedFile('coordinator_signature_file');
        if ($coordSignatureFile && $coordSignatureFile->getError() === UPLOAD_ERR_OK) {
            $coordPath = $signatureService->saveFromUpload($novelty->id, $coordSignatureFile, $user->id, 'coordinator');
            if ($coordPath) {
                $novelty->coordinator_signature = $coordPath;
            }
        }
        $coordBase64 = $this->request->getData('coordinator_signature_base64');
        if (!$novelty->coordinator_signature && !empty($coordBase64)) {
            $coordPath = $signatureService->saveFromBase64($novelty->id, $coordBase64, $user->id, 'coordinator');
            if ($coordPath) {
                $novelty->coordinator_signature = $coordPath;
            }
        }

        if ($this->EmployeeNovelties->save($novelty)) {
            $this->Flash->success('Novedad aprobada.');
        } else {
            $this->Flash->error('No se pudo aprobar la novedad.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function reject($id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['Employees']);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        if (!$this->_canApproveNovelty($user, $novelty)) {
            $this->Flash->error('No tiene permisos para rechazar esta novedad.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $novelty->status = NoveltyConstants::STATUS_REJECTED;
        $novelty->approved_by = $user->id;
        $novelty->approved_at = new DateTime();

        $observations = $this->request->getData('observations');
        if ($observations) {
            $novelty->observations = $observations;
        }

        if ($this->EmployeeNovelties->save($novelty)) {
            $this->Flash->success('Novedad rechazada.');
        } else {
            $this->Flash->error('No se pudo rechazar la novedad.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    private function _getNoveltyTypesGrouped(): array
    {
        $types = $this->EmployeeNovelties->NoveltyTypes->find()
            ->contain(['ChildNoveltyTypes' => ['sort' => ['ChildNoveltyTypes.name' => 'ASC']]])
            ->where(['NoveltyTypes.parent_id IS' => null])
            ->order(['NoveltyTypes.name' => 'ASC'])
            ->all();

        $grouped = [];
        foreach ($types as $type) {
            if (!empty($type->child_novelty_types)) {
                $children = [];
                foreach ($type->child_novelty_types as $child) {
                    $children[$child->id] = $child->name;
                }
                $grouped[$type->name] = $children;
            } else {
                $grouped[$type->id] = $type->name;
            }
        }

        return $grouped;
    }

    private function _canApproveNovelty(object $user, object $novelty): bool
    {
        $roleName = $this->_getUserRoleName($user);
        if ($roleName === 'Administrador') {
            return true;
        }

        if ($novelty->status !== NoveltyConstants::STATUS_PENDING) {
            return false;
        }

        $employee = $novelty->employee;
        if (!$employee || !$employee->supervisor_position_id) {
            return false;
        }

        $employeesTable = TableRegistry::getTableLocator()->get('Employees');
        $supervisorEmployee = $employeesTable->find()
            ->where([
                'position_id' => $employee->supervisor_position_id,
                'active' => true,
            ])
            ->first();

        if (!$supervisorEmployee) {
            return false;
        }

        return $supervisorEmployee->email === $user->email;
    }

    private function _getSubordinateEmployeeIds(object $user): array
    {
        $employeesTable = TableRegistry::getTableLocator()->get('Employees');

        $userEmployee = $employeesTable->find()
            ->where(['email' => $user->email, 'active' => true])
            ->first();

        if (!$userEmployee || !$userEmployee->position_id) {
            return [];
        }

        $subordinates = $employeesTable->find()
            ->where(['supervisor_position_id' => $userEmployee->position_id])
            ->select(['id'])
            ->all();

        return array_map(fn($e) => $e->id, $subordinates->toArray());
    }
}

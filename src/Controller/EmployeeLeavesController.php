<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\ORM\TableRegistry;

class EmployeeLeavesController extends AppController
{
    public function index()
    {
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $roleName = $this->_getUserRoleName($user);

        $query = $this->EmployeeLeaves->find()
            ->contain(['Employees', 'LeaveTypes', 'RequestedByUsers'])
            ->order(['EmployeeLeaves.created' => 'DESC']);

        // Filter: non-admin users see only leaves for employees they supervise
        if ($roleName !== 'Administrador') {
            $subordinateIds = $this->_getSubordinateEmployeeIds($user);
            if (!empty($subordinateIds)) {
                $query->where(['EmployeeLeaves.employee_id IN' => $subordinateIds]);
            } else {
                // No subordinates — show nothing
                $query->where(['1 = 0']);
            }
        }

        // Optional status filter
        $statusFilter = $this->request->getQuery('status');
        if ($statusFilter) {
            $query->where(['EmployeeLeaves.status' => $statusFilter]);
        }

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $employeeLeaves = $this->paginate($query);

        $this->set(compact('employeeLeaves', 'statusFilter'));
    }

    public function view($id = null)
    {
        $employeeLeave = $this->EmployeeLeaves->get($id, contain: [
            'Employees',
            'LeaveTypes',
            'ApprovedByUsers',
            'RequestedByUsers',
        ]);

        $user = $this->Authentication->getIdentity()->getOriginalData();
        $canApprove = $this->_canApproveLeave($user, $employeeLeave);

        $this->set(compact('employeeLeave', 'canApprove'));
    }

    public function add()
    {
        $employeeLeave = $this->EmployeeLeaves->newEmptyEntity();
        if ($this->request->is('post')) {
            $user = $this->Authentication->getIdentity()->getOriginalData();
            $data = $this->request->getData();
            $data['requested_by'] = $user->id;
            $data['status'] = 'pendiente';

            $employeeLeave = $this->EmployeeLeaves->patchEntity($employeeLeave, $data);
            if ($this->EmployeeLeaves->save($employeeLeave)) {
                $this->Flash->success(__('La solicitud de permiso ha sido creada.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo crear la solicitud. Intente de nuevo.'));
        }

        $employees = $this->EmployeeLeaves->Employees->find('list', [
            'keyField' => 'id',
            'valueField' => 'full_name',
        ])->all();
        $leaveTypes = $this->EmployeeLeaves->LeaveTypes->find('list')->all();

        $this->set(compact('employeeLeave', 'employees', 'leaveTypes'));
    }

    public function approve($id = null)
    {
        $this->request->allowMethod(['post']);
        $employeeLeave = $this->EmployeeLeaves->get($id, contain: ['Employees']);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        if (!$this->_canApproveLeave($user, $employeeLeave)) {
            $this->Flash->error('No tiene permisos para aprobar este permiso.');
            return $this->redirect(['action' => 'view', $id]);
        }

        $employeeLeave->status = 'aprobado';
        $employeeLeave->approved_by = $user->id;
        $employeeLeave->approved_at = new \DateTime();

        if ($this->EmployeeLeaves->save($employeeLeave)) {
            $this->Flash->success('Permiso aprobado.');
        } else {
            $this->Flash->error('No se pudo aprobar el permiso.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function reject($id = null)
    {
        $this->request->allowMethod(['post']);
        $employeeLeave = $this->EmployeeLeaves->get($id, contain: ['Employees']);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        if (!$this->_canApproveLeave($user, $employeeLeave)) {
            $this->Flash->error('No tiene permisos para rechazar este permiso.');
            return $this->redirect(['action' => 'view', $id]);
        }

        $employeeLeave->status = 'rechazado';
        $employeeLeave->approved_by = $user->id;
        $employeeLeave->approved_at = new \DateTime();

        $observations = $this->request->getData('observations');
        if ($observations) {
            $employeeLeave->observations = $observations;
        }

        if ($this->EmployeeLeaves->save($employeeLeave)) {
            $this->Flash->success('Permiso rechazado.');
        } else {
            $this->Flash->error('No se pudo rechazar el permiso.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Check if user can approve/reject a leave request.
     * Admin can always approve. Otherwise, user must hold the supervisor position.
     */
    private function _canApproveLeave(object $user, object $employeeLeave): bool
    {
        $roleName = $this->_getUserRoleName($user);
        if ($roleName === 'Administrador') {
            return true;
        }

        if ($employeeLeave->status !== 'pendiente') {
            return false;
        }

        $employee = $employeeLeave->employee;
        if (!$employee || !$employee->supervisor_position_id) {
            return false;
        }

        // Find if current user is linked to an employee with the supervisor position
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

        // Match by email (user email = employee email)
        return $supervisorEmployee->email === $user->email;
    }

    /**
     * Get employee IDs that the current user supervises.
     */
    private function _getSubordinateEmployeeIds(object $user): array
    {
        $employeesTable = TableRegistry::getTableLocator()->get('Employees');

        // Find position of the current user's employee record
        $userEmployee = $employeesTable->find()
            ->where(['email' => $user->email, 'active' => true])
            ->first();

        if (!$userEmployee || !$userEmployee->position_id) {
            return [];
        }

        // Find employees whose supervisor_position_id matches the user's position
        $subordinates = $employeesTable->find()
            ->where(['supervisor_position_id' => $userEmployee->position_id])
            ->select(['id'])
            ->all();

        return array_map(fn($e) => $e->id, $subordinates->toArray());
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

class ApprovalTokenService
{
    public function generateToken(string $entityType, int $entityId, int $createdBy, int $hoursValid = 48): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = new \DateTime("+{$hoursValid} hours");

        $table = TableRegistry::getTableLocator()->get('ApprovalTokens');
        $entity = $table->newEntity([
            'token' => $token,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'created_by' => $createdBy,
            'expires_at' => $expiresAt,
        ]);

        $table->save($entity);

        return $token;
    }

    public function validateToken(string $token): ?object
    {
        $table = TableRegistry::getTableLocator()->get('ApprovalTokens');
        $record = $table->find()
            ->where(['token' => $token])
            ->first();

        if (!$record) {
            return null;
        }

        // Check if already used
        if ($record->used_at !== null) {
            return null;
        }

        // Check expiration
        if ($record->expires_at < new \DateTime()) {
            return null;
        }

        return $record;
    }

    public function consumeToken(
        string $token,
        string $action,
        ?string $observations,
        ?string $ip,
        ?string $userAgent
    ): bool {
        $table = TableRegistry::getTableLocator()->get('ApprovalTokens');
        $record = $table->find()
            ->where(['token' => $token])
            ->first();

        if (!$record || $record->used_at !== null) {
            return false;
        }

        $record->used_at = new \DateTime();
        $record->action_taken = $action;
        $record->observations = $observations;
        $record->ip_address = $ip;
        $record->user_agent = $userAgent;

        if (!$table->save($record)) {
            return false;
        }

        // Apply action to the entity
        return $this->applyAction($record->entity_type, $record->entity_id, $action);
    }

    private function applyAction(string $entityType, int $entityId, string $action): bool
    {
        switch ($entityType) {
            case 'invoices':
                return $this->applyInvoiceAction($entityId, $action);
            case 'employee_leaves':
                return $this->applyLeaveAction($entityId, $action);
            default:
                return false;
        }
    }

    private function applyInvoiceAction(int $invoiceId, string $action): bool
    {
        $table = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $table->get($invoiceId);

        if ($action === 'approve') {
            $pipeline = new InvoicePipelineService();
            $nextStatus = $pipeline->getNextStatus($invoice->pipeline_status);
            if ($nextStatus) {
                $invoice->pipeline_status = $nextStatus;
                return (bool)$table->save($invoice);
            }
        }

        return true;
    }

    private function applyLeaveAction(int $leaveId, string $action): bool
    {
        $table = TableRegistry::getTableLocator()->get('EmployeeLeaves');
        $leave = $table->get($leaveId);

        if ($action === 'approve') {
            $leave->status = 'aprobado';
            $leave->approved_at = new \DateTime();
        } elseif ($action === 'reject') {
            $leave->status = 'rechazado';
            $leave->approved_at = new \DateTime();
        }

        return (bool)$table->save($leave);
    }

    public function getEntity(string $entityType, int $entityId): ?object
    {
        $tableMap = [
            'invoices' => 'Invoices',
            'employee_leaves' => 'EmployeeLeaves',
        ];

        $tableName = $tableMap[$entityType] ?? null;
        if (!$tableName) {
            return null;
        }

        $table = TableRegistry::getTableLocator()->get($tableName);

        try {
            $contain = [];
            if ($entityType === 'invoices') {
                $contain = ['Providers'];
            } elseif ($entityType === 'employee_leaves') {
                $contain = ['Employees', 'LeaveTypes'];
            }

            return $table->get($entityId, contain: $contain);
        } catch (\Exception $e) {
            return null;
        }
    }
}

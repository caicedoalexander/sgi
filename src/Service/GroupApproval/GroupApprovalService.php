<?php
declare(strict_types=1);

namespace App\Service\GroupApproval;

use App\Constants\ApprovalConstants;
use App\Constants\InvoiceConstants;
use App\Service\Approval\ApprovalTokenManager;
use App\Service\Approval\ApprovalUrlBuilder;
use App\Service\ServiceResult;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Exception;

/**
 * Base multi-aprobador a nivel de grupo (reintegro / legalización de anticipo).
 * Espejo genérico de InvoiceApprovalService: asigna aprobadores, emite tokens,
 * detecta quórum "todos aprueban" y consume el token con FOR UPDATE. El efecto
 * de dominio lo aportan las subclases (onAllApproved / onRejected).
 */
abstract class GroupApprovalService
{
    protected Table $approvalsTable;

    /**
     * Resolve the approvals table configured by the concrete subclass.
     */
    public function __construct()
    {
        $this->approvalsTable = TableRegistry::getTableLocator()->get($this->tableName());
    }

    /**
     * CakePHP table name backing the group approvals.
     *
     * @return string
     */
    abstract protected function tableName(): string;

    /**
     * FK column linking an approval row to its parent entity.
     *
     * @return string
     */
    abstract protected function fkField(): string;

    /**
     * Send the approval link email to a single approver.
     *
     * @param object $entity Parent entity under approval.
     * @param string $url Signed approval URL for the approver.
     * @param int $userId Approver user ID.
     * @param int $createdBy User ID that assigned the approver.
     * @return void
     */
    abstract protected function notifyApprover(object $entity, string $url, int $userId, int $createdBy): void;

    /**
     * Domain effect applied once every approver has approved.
     *
     * @param int $entityId Parent entity ID.
     * @param int $approverUserId User ID of the last approver.
     * @return void
     */
    abstract protected function onAllApproved(int $entityId, int $approverUserId): void;

    /**
     * Domain effect applied when any approver rejects.
     *
     * @param int $entityId Parent entity ID.
     * @param int $approverUserId User ID of the rejecting approver.
     * @param string|null $observations Optional rejection reason.
     * @return void
     */
    abstract protected function onRejected(int $entityId, int $approverUserId, ?string $observations): void;

    /**
     * Generate and stamp a fresh hashed token (with TTL) onto an approval row.
     *
     * @param object $approval Approval entity to receive the token.
     * @return string The plaintext secret to embed in the approval URL.
     */
    public function applyFreshToken(object $approval): string
    {
        $secret = ApprovalTokenManager::generateSecret();
        $approval->token_hash = ApprovalTokenManager::hashSecret($secret);
        $approval->token_expires_at = new DateTime('+' . InvoiceConstants::APPROVAL_TOKEN_HOURS . ' hours');

        return $secret;
    }

    /**
     * List the currently active approval rows for an entity (with Users/Roles).
     *
     * @param int $entityId Parent entity ID.
     * @return array
     */
    public function getCurrentApprovals(int $entityId): array
    {
        return $this->approvalsTable->find()
            ->where([$this->fkField() => $entityId, 'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE])
            ->contain(['Users' => ['Roles']])
            ->orderBy([$this->approvalsTable->getAlias() . '.created' => 'ASC'])
            ->all()
            ->toArray();
    }

    /**
     * Tally active approvals into total/approved/rejected/pending counters.
     *
     * @param int $entityId Parent entity ID.
     * @return array
     */
    public function getApprovalSummary(int $entityId): array
    {
        $total = 0;
        $approved = 0;
        $rejected = 0;
        $pending = 0;
        foreach ($this->getCurrentApprovals($entityId) as $r) {
            $total++;
            match ($r->status) {
                InvoiceConstants::APPROVER_STATUS_APPROVED => $approved++,
                InvoiceConstants::APPROVER_STATUS_REJECTED => $rejected++,
                default => $pending++,
            };
        }

        return compact('total', 'approved', 'rejected', 'pending');
    }

    /**
     * Find the pending, unexpired approval row matching a plaintext token.
     *
     * @param string $token Plaintext approval secret from the URL.
     * @return object|null The matching approval entity (with Users), or null.
     */
    public function validateToken(string $token): ?object
    {
        return $this->approvalsTable->find()
            ->where([
                'token_hash' => ApprovalTokenManager::hashSecret($token),
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
                'token_expires_at >' => new DateTime(),
            ])
            ->contain(['Users'])
            ->first();
    }

    /**
     * Whether every active approval for the entity is in the approved state.
     *
     * @param int $entityId Parent entity id.
     * @return bool True when at least one row exists and all are approved.
     */
    public function areAllApproved(int $entityId): bool
    {
        $rows = $this->getCurrentApprovals($entityId);
        if (empty($rows)) {
            return false;
        }
        foreach ($rows as $r) {
            if ($r->status !== InvoiceConstants::APPROVER_STATUS_APPROVED) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the entity still has pending, unexpired approvals.
     *
     * @param int $entityId Parent entity id.
     * @return bool
     */
    public function hasPendingApprovals(int $entityId): bool
    {
        return $this->approvalsTable->find()
            ->where([
                $this->fkField() => $entityId,
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
                'token_expires_at >' => new DateTime(),
            ])->count() > 0;
    }

    /**
     * Whether the entity has any active (pending or approved) approval rows.
     *
     * @param int $entityId Parent entity id.
     * @return bool
     */
    public function hasAnyActiveApprovals(int $entityId): bool
    {
        return $this->approvalsTable->find()
            ->where([$this->fkField() => $entityId, 'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE])
            ->count() > 0;
    }

    /** Marca Reemplazada todas las aprobaciones activas del grupo (edición/regresión). */
    public function supersedeAll(int $entityId): void
    {
        $this->approvalsTable->updateAll(
            ['status' => InvoiceConstants::APPROVER_STATUS_SUPERSEDED, 'token_hash' => null, 'token_expires_at' => null],
            [$this->fkField() => $entityId, 'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE],
        );
    }

    /**
     * Create a pending approval row per user, then email each signed link.
     *
     * @param object $entity Parent entity under approval.
     * @param array $userIds Approver user ids to assign.
     * @param string $baseUrl Base URL used to build each approval link.
     * @param int $createdBy User id assigning the approvers.
     * @return \App\Service\ServiceResult Ok with assigned count, or fail with errors.
     */
    public function assignApprovers(object $entity, array $userIds, string $baseUrl, int $createdBy): ServiceResult
    {
        if (empty($userIds)) {
            return ServiceResult::fail(['Debe seleccionar al menos un aprobador']);
        }
        $pending = [];
        $expiresAt = new DateTime('+' . InvoiceConstants::APPROVAL_TOKEN_HOURS . ' hours');
        foreach ($userIds as $userId) {
            $secret = ApprovalTokenManager::generateSecret();
            $approval = $this->approvalsTable->newEntity([
                $this->fkField() => $entity->id,
                'user_id' => (int)$userId,
                'token_hash' => ApprovalTokenManager::hashSecret($secret),
                'token_expires_at' => $expiresAt,
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
            ]);
            if (!$this->approvalsTable->save($approval)) {
                return ServiceResult::fail(["Error al asignar aprobador ID {$userId}"]);
            }
            $pending[] = ['userId' => (int)$userId, 'url' => ApprovalUrlBuilder::approveUrl($baseUrl, $secret)];
        }

        $errors = [];
        foreach ($pending as $item) {
            try {
                $this->notifyApprover($entity, $item['url'], $item['userId'], $createdBy);
            } catch (Exception $e) {
                $errors[] = sprintf('Aprobador asignado, pero el correo a usuario ID %d falló: %s', $item['userId'], $e->getMessage());
            }
        }

        return empty($errors) ? ServiceResult::ok(['assigned' => count($pending)]) : ServiceResult::fail($errors);
    }

    /**
     * Send first-time approval links, refusing when active approvals already exist.
     *
     * @param object $entity Parent entity under approval.
     * @param array $userIds Approver user ids to assign.
     * @param string $baseUrl Base URL used to build each approval link.
     * @param int $createdBy User id assigning the approvers.
     * @return \App\Service\ServiceResult
     */
    public function sendApprovalLinks(object $entity, array $userIds, string $baseUrl, int $createdBy): ServiceResult
    {
        if ($this->hasAnyActiveApprovals((int)$entity->id)) {
            return ServiceResult::fail(['Ya existen aprobaciones; use Modificar aprobadores.']);
        }

        return $this->assignApprovers($entity, $userIds, $baseUrl, $createdBy);
    }

    /**
     * Supersede existing approvals and reassign a new approver set (reason required).
     *
     * @param object $entity Parent entity under approval.
     * @param array $userIds New approver user ids.
     * @param string $reason Mandatory justification for the change.
     * @param string $baseUrl Base URL used to build each approval link.
     * @param int $userId User id performing the change.
     * @return \App\Service\ServiceResult
     */
    public function modifyApprovers(object $entity, array $userIds, string $reason, string $baseUrl, int $userId): ServiceResult
    {
        if (trim($reason) === '') {
            return ServiceResult::fail(['El motivo es obligatorio.']);
        }
        if (empty($userIds)) {
            return ServiceResult::fail(['Debe seleccionar al menos un aprobador.']);
        }
        $this->supersedeAll((int)$entity->id);

        return $this->assignApprovers($entity, $userIds, $baseUrl, $userId);
    }

    /**
     * Consume an approval token transactionally (FOR UPDATE), record the response,
     * and trigger the domain effect when the group is fully approved or rejected.
     *
     * @param string $token Plaintext approval secret from the URL.
     * @param string $action Approve or reject action.
     * @param string|null $observations Optional response note.
     * @param string|null $ip Responder IP address.
     * @param string|null $userAgent Responder user agent.
     * @return \App\Service\ServiceResult Ok with allApproved/rejected/entity_id, or fail.
     */
    public function processResponse(string $token, string $action, ?string $observations, ?string $ip, ?string $userAgent): ServiceResult
    {
        $connection = $this->approvalsTable->getConnection();

        return $connection->transactional(function () use ($token, $action, $observations, $ip, $userAgent) {
            $approval = $this->approvalsTable->find()
                ->where([
                    'token_hash' => ApprovalTokenManager::hashSecret($token),
                    'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
                    'token_expires_at >' => new DateTime(),
                ])
                ->epilog('FOR UPDATE')
                ->first();

            if (!$approval) {
                return ServiceResult::fail(['Token inválido o expirado']);
            }

            $approval->status = $action === ApprovalConstants::ACTION_APPROVE
                ? InvoiceConstants::APPROVER_STATUS_APPROVED
                : InvoiceConstants::APPROVER_STATUS_REJECTED;
            $approval->responded_at = new DateTime();
            $approval->observations = $observations;
            $approval->ip_address = $ip;
            $approval->user_agent = $userAgent;
            $approval->token_hash = null;
            if (!$this->approvalsTable->save($approval)) {
                return ServiceResult::fail(['Error al guardar respuesta']);
            }

            $entityId = (int)$approval->{$this->fkField()};

            if ($action === ApprovalConstants::ACTION_REJECT) {
                $this->approvalsTable->updateAll(
                    ['token_hash' => null, 'token_expires_at' => null],
                    [$this->fkField() => $entityId, 'status' => InvoiceConstants::APPROVER_STATUS_PENDING, 'id !=' => $approval->id],
                );
                $this->onRejected($entityId, (int)$approval->user_id, $observations);

                return ServiceResult::ok(['allApproved' => false, 'rejected' => true, 'entity_id' => $entityId]);
            }

            $allApproved = $this->areAllApproved($entityId);
            if ($allApproved) {
                $this->onAllApproved($entityId, (int)$approval->user_id);
            }

            return ServiceResult::ok(['allApproved' => $allApproved, 'rejected' => false, 'entity_id' => $entityId]);
        });
    }
}

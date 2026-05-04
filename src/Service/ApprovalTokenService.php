<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Service\Strategy\InvoiceApprovalStrategy;
use App\Service\Strategy\NoveltyApprovalStrategy;
use Cake\ORM\TableRegistry;
use DateTime;

/**
 * Generic single-entity external approval via SHA256 tokens (approval_tokens table).
 *
 * Handles one token per entity (invoice or novelty). Uses Strategy pattern
 * to delegate entity-specific approval logic.
 *
 * For multi-approver invoice workflows, see InvoiceApprovalService which
 * manages its own tokens in the invoice_approvals table.
 */
class ApprovalTokenService
{
    /**
     * @var array<string, \App\Service\Strategy\ApprovalStrategyInterface>
     */
    private array $strategies;

    public function __construct(
        InvoiceApprovalStrategy $invoiceStrategy,
        NoveltyApprovalStrategy $noveltyStrategy,
    ) {
        $this->strategies = [
            'invoices' => $invoiceStrategy,
            'employee_novelties' => $noveltyStrategy,
        ];
    }

    /**
     * @param string $entityType Entity type key.
     * @param int $entityId Entity ID.
     * @param int $createdBy User who created the token.
     * @param int $hoursValid Token validity in hours.
     * @return string The generated token.
     */
    public function generateToken(
        string $entityType,
        int $entityId,
        int $createdBy,
        int $hoursValid = InvoiceConstants::APPROVAL_TOKEN_HOURS,
    ): string {
        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTime("+{$hoursValid} hours");

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

    /**
     * @param string $token The token string.
     * @return object|null
     */
    public function validateToken(string $token): ?object
    {
        $table = TableRegistry::getTableLocator()->get('ApprovalTokens');
        $record = $table->find()
            ->where(['token' => $token])
            ->first();

        if (!$record) {
            return null;
        }

        if ($record->used_at !== null) {
            return null;
        }

        if ($record->expires_at < new DateTime()) {
            return null;
        }

        return $record;
    }

    /**
     * @param string $token The token string.
     * @param string $action The action (approve/reject).
     * @param string|null $observations Optional observations.
     * @param string|null $ip Client IP address.
     * @param string|null $userAgent Client user agent.
     * @param string|null $approvalDate Optional approval date.
     * @param int|null $approvedByUserId Optional approver user ID.
     * @return bool
     */
    public function consumeToken(
        string $token,
        string $action,
        ?string $observations,
        ?string $ip,
        ?string $userAgent,
        ?string $approvalDate = null,
        ?int $approvedByUserId = null,
    ): bool {
        $table = TableRegistry::getTableLocator()->get('ApprovalTokens');

        // Marcar el token como consumido bajo lock pesimista para evitar
        // que requests concurrentes con el mismo token disparen la strategy dos veces.
        $consumed = $table->getConnection()->transactional(
            function () use ($table, $token, $action, $observations, $ip, $userAgent, $approvedByUserId): ?object {
                $record = $table->find()
                    ->where(['token' => $token])
                    ->epilog('FOR UPDATE')
                    ->first();

                if (!$record || $record->used_at !== null) {
                    return null;
                }

                if ($record->expires_at < new DateTime()) {
                    return null;
                }

                $record->used_at = new DateTime();
                $record->action_taken = $action;
                $record->observations = $observations;
                $record->ip_address = $ip;
                $record->user_agent = $userAgent;
                $record->approved_by_user_id = $approvedByUserId;

                return $table->save($record) ? $record : null;
            },
        );

        if (!$consumed) {
            return false;
        }

        $strategy = $this->strategies[$consumed->entity_type] ?? null;

        return $strategy
            ? $strategy->apply($consumed->entity_id, $action, $observations, $approvedByUserId, $approvalDate)
            : false;
    }

    /**
     * @param string $entityType Entity type key.
     * @param int $entityId Entity ID.
     * @return object|null
     */
    public function getEntity(string $entityType, int $entityId): ?object
    {
        $strategy = $this->strategies[$entityType] ?? null;

        return $strategy ? $strategy->getEntity($entityId) : null;
    }
}

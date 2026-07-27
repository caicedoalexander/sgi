<?php
declare(strict_types=1);

namespace App\Service\Approval\Store;

use App\Service\Approval\ApprovalTokenStore;
use App\Service\Approval\ConsumeContext;
use Cake\Database\Connection;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use DateTime;
use DateTimeInterface;

/**
 * Adaptador del puerto `ApprovalTokenStore` sobre la tabla `approval_tokens`
 * (sistema genérico 1:1). Persiste y consulta por la columna `token_hash`
 * (SHA-256 del token original). Semántica single-use: un token por entidad,
 * marcado consumido vía la columna `used_at`.
 */
final class SingleUseTokenStore implements ApprovalTokenStore
{
    private Table $table;

    /**
     * @param \Cake\ORM\Table|null $table Tabla ApprovalTokens (inyectable para tests).
     */
    public function __construct(?Table $table = null)
    {
        $this->table = $table ?? TableRegistry::getTableLocator()->get('ApprovalTokens');
    }

    /**
     * @inheritDoc
     */
    public function getConnection(): Connection
    {
        return $this->table->getConnection();
    }

    /**
     * @inheritDoc
     */
    public function put(
        string $tokenHash,
        string $entityType,
        int $entityId,
        int $createdBy,
        DateTimeInterface $expiresAt,
    ): void {
        $entity = $this->table->newEntity([
            'token_hash' => $tokenHash,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'created_by' => $createdBy,
            'expires_at' => $expiresAt,
        ]);

        $this->table->save($entity);
    }

    /**
     * @inheritDoc
     */
    public function findValid(string $tokenHash, bool $forUpdate = false): ?object
    {
        $query = $this->table->find()->where(['token_hash' => $tokenHash]);
        if ($forUpdate) {
            $query->epilog('FOR UPDATE');
        }

        $record = $query->first();

        if (!$record || $record->used_at !== null) {
            return null;
        }

        if ($record->expires_at < new DateTime()) {
            return null;
        }

        return $record;
    }

    /**
     * @inheritDoc
     */
    public function markConsumed(object $record, string $action, ConsumeContext $context): bool
    {
        $record->used_at = new DateTime();
        $record->action_taken = $action;
        $record->observations = $context->observations;
        $record->ip_address = $context->ip;
        $record->user_agent = $context->userAgent;
        $record->approved_by_user_id = $context->approvedByUserId;

        return (bool)$this->table->save($record);
    }
}

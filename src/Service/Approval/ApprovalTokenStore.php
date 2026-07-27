<?php
declare(strict_types=1);

namespace App\Service\Approval;

use Cake\Database\Connection;
use DateTimeInterface;

/**
 * Puerto de persistencia del ciclo de vida de un token de aprobación.
 *
 * Cada sistema de aprobación lo implementa con su propia tabla y semántica de
 * "consumido": `SingleUseTokenStore` (approval_tokens, marca `used_at`).
 * El núcleo `ApprovalTokenManager` depende de esta abstracción, no de una
 * tabla concreta. Los valores que circulan por este puerto son **hashes**
 * (SHA-256) del token original — nunca el cleartext.
 */
interface ApprovalTokenStore
{
    /**
     * Conexión usada por el manager para envolver el consumo en una transacción.
     */
    public function getConnection(): Connection;

    /**
     * Persiste un token recién emitido (su hash) asociado a una entidad.
     */
    public function put(
        string $tokenHash,
        string $entityType,
        int $entityId,
        int $createdBy,
        DateTimeInterface $expiresAt,
    ): void;

    /**
     * Devuelve el registro de un token vigente por su hash (no consumido y no
     * expirado), o `null`. Con `$forUpdate` aplica lock pesimista para el consumo.
     */
    public function findValid(string $tokenHash, bool $forUpdate = false): ?object;

    /**
     * Marca el registro como consumido según la semántica del store.
     */
    public function markConsumed(object $record, string $action, ConsumeContext $context): bool;
}

<?php
declare(strict_types=1);

namespace App\Service\Approval;

use App\Constants\InvoiceConstants;
use DateTime;

/**
 * Núcleo reutilizable del ciclo de vida del token de aprobación, compartido por
 * los dos sistemas (genérico y multi-aprobador) vía el puerto `ApprovalTokenStore`.
 *
 * Gestiona el secreto (generación, TTL, validación, consumo single-use bajo lock
 * pesimista). NO conoce el dominio: el efecto de la aprobación (strategy, quórum)
 * lo aplica el caller con el registro que devuelve `consume()`.
 *
 * Los callers que pasan por `issue()`/`consume()` (sistema genérico single-use)
 * persisten el secreto hasheado (SHA256) vía `hashSecret()`; el claro solo
 * viaja en la URL. El sistema multi-aprobador (`InvoiceApprovalService`) reusa
 * `hashSecret()`/`generateSecret()` de forma estática y se alinea a este mismo
 * modelo en la Ola 3.
 */
final class ApprovalTokenManager
{
    /**
     * Genera un secreto de token aleatorio (256 bits) en hexadecimal (64 chars).
     *
     * Punto único del algoritmo del secreto, compartido por ambos sistemas de
     * aprobación (genérico y multi-aprobador).
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Digest en reposo del secreto del token. SHA256 determinista (sin sal): el
     * secreto es 256 bits de entropía, inmune a diccionario/rainbow, y el digest
     * debe ser buscable (WHERE token_hash = ?). Punto único del algoritmo de hash.
     */
    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Emite y persiste un token aleatorio (256 bits) asociado a una entidad.
     */
    public function issue(
        ApprovalTokenStore $store,
        string $entityType,
        int $entityId,
        int $createdBy,
        int $hoursValid = InvoiceConstants::APPROVAL_TOKEN_HOURS,
    ): string {
        $token = self::generateSecret();
        $store->put(self::hashSecret($token), $entityType, $entityId, $createdBy, new DateTime("+{$hoursValid} hours"));

        return $token;
    }

    /**
     * Resuelve un token vigente a su registro, sin consumirlo. `null` si no
     * existe, ya fue consumido o expiró.
     */
    public function resolve(ApprovalTokenStore $store, string $token): ?object
    {
        return $store->findValid(self::hashSecret($token));
    }

    /**
     * Consume un token single-use bajo lock pesimista. Si se provee `$domainEffect`,
     * lo ejecuta DENTRO de la misma transacción, después de marcar el consumo: si el
     * efecto no devuelve `true`, la transacción devuelve `false` y CakePHP revierte
     * todo (incluido el `markConsumed`), dejando el token vivo. Devuelve el registro
     * consumido, o `null` si el token no es válido o el efecto de dominio falló.
     */
    public function consume(
        ApprovalTokenStore $store,
        string $token,
        string $action,
        ConsumeContext $context,
        ?callable $domainEffect = null,
    ): ?object {
        $tokenHash = self::hashSecret($token);

        $result = $store->getConnection()->transactional(
            function () use ($store, $tokenHash, $action, $context, $domainEffect): object|false {
                $record = $store->findValid($tokenHash, true);
                if (!$record) {
                    return false;
                }
                if (!$store->markConsumed($record, $action, $context)) {
                    return false;
                }
                if ($domainEffect !== null && $domainEffect($record) !== true) {
                    return false;
                }

                return $record;
            },
        );

        return $result === false ? null : $result;
    }
}

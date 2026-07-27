<?php
declare(strict_types=1);

namespace App\Service\Approval;

use DateTimeInterface;

/**
 * Forma común del registro de token de aprobación ya resuelto, compartida por
 * los dos sistemas (multi-aprobador `invoice_approvals` y genérico
 * `approval_tokens`).
 *
 * Reemplaza el objeto anónimo `(object)[...]` que el controlador de aprobación
 * externa sintetizaba a mano para el caso multi-aprobador, de modo que la vista
 * reciba siempre la misma forma con independencia del sistema de origen.
 */
final class TokenRecord
{
    /**
     * @param string $entityType Tipo de entidad (invoices|employee_novelties).
     * @param int $entityId ID de la entidad.
     * @param \DateTimeInterface $expiresAt Expiración del token.
     */
    public function __construct(
        public readonly string $entityType,
        public readonly int $entityId,
        public readonly DateTimeInterface $expiresAt,
    ) {
    }
}

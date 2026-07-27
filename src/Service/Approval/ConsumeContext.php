<?php
declare(strict_types=1);

namespace App\Service\Approval;

/**
 * Datos forenses/contextuales del consumo de un token de aprobación.
 *
 * Agrupa los parámetros que se persisten al marcar un token como consumido
 * (auditoría: IP, user agent, quién aprobó) más las observaciones del aprobador.
 */
final class ConsumeContext
{
    /**
     * @param string|null $observations Observaciones del aprobador.
     * @param string|null $ip IP del cliente.
     * @param string|null $userAgent User agent del cliente.
     * @param string|null $approvalDate Fecha de aprobación.
     * @param int|null $approvedByUserId Usuario que aprobó.
     */
    public function __construct(
        public readonly ?string $observations = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $approvalDate = null,
        public readonly ?int $approvedByUserId = null,
    ) {
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Dto;

/**
 * Reporte estructurado de requisitos pendientes de las facturas hijas de un
 * registro padre (Reintegro / Caja Menor / Anticipo). Misma fuente para el
 * gate de avance del padre y el checklist de la vista (cero drift).
 */
final readonly class GroupReadinessReport
{
    /**
     * @param array<int, string> $dianPending id ⇒ invoice_number
     * @param array<int, string> $supportMissing id ⇒ invoice_number
     */
    public function __construct(
        public array $dianPending = [],
        public array $supportMissing = [],
    ) {
    }

    /** ¿Hay al menos un requisito pendiente (DIAN o soporte)? */
    public function isBlocked(): bool
    {
        return $this->dianPending !== [] || $this->supportMissing !== [];
    }

    /** @return array<string> Mensajes ES para errores de transición / flash. */
    public function toMessages(): array
    {
        $messages = [];
        if ($this->dianPending !== []) {
            $messages[] = 'Validación DIAN pendiente en: ' . implode(', ', $this->dianPending);
        }
        if ($this->supportMissing !== []) {
            $messages[] = 'Soporte pendiente en: ' . implode(', ', $this->supportMissing);
        }

        return $messages;
    }
}

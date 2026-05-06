<?php
declare(strict_types=1);

namespace App\Service\Dto;

use Cake\ORM\Entity;
use DateTimeInterface;

/**
 * Vista uniforme del pago bulk de un dominio que guarda **un único pago como
 * columnas en su tabla principal** (Refunds, PettyCashRecords). Materializa
 * esas columnas en la forma que espera el element compartido
 * `templates/element/payment_section.php`, garantizando tipado estático:
 * cualquier mismatch falla en IDE en lugar de runtime.
 *
 * Convención del proyecto — ver auditoría 2026-05-06 sección 9.
 */
final readonly class BulkPaymentView
{
    /**
     * @param int $id ID del registro propietario.
     * @param \Cake\ORM\Entity|null $banking_entity Entidad bancaria asociada.
     * @param float|int|null $amount Monto del pago.
     * @param \DateTimeInterface|null $payment_date Fecha del pago.
     * @param string $status Estado del pago (pendiente/autorizado).
     * @param bool $authorized True si el pago fue autorizado.
     * @param \Cake\ORM\Entity|null $authorized_by_user Usuario que autorizó.
     * @param \DateTimeInterface|null $authorized_date Fecha de autorización.
     * @param \Cake\ORM\Entity|null $created_by_user Usuario que registró.
     * @param string|null $rejection_reason Motivo de rechazo si aplica.
     */
    public function __construct(
        public int $id,
        public ?Entity $banking_entity,
        public float|int|null $amount,
        public ?DateTimeInterface $payment_date,
        public string $status,
        public bool $authorized,
        public ?Entity $authorized_by_user,
        public ?DateTimeInterface $authorized_date,
        public ?Entity $created_by_user,
        public ?string $rejection_reason,
    ) {
    }
}

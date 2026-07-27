<?php
declare(strict_types=1);

namespace App\Service\Dto;

use Cake\I18n\DateTime;

/**
 * Representación normalizada de una aprobación (factura o novedad) para la
 * bandeja personal del aprobador. Inmutable.
 */
final readonly class ApprovalInboxItem
{
    /**
     * @param string $module Módulo de origen (factura o novedad).
     * @param int $entityId Id de la entidad aprobable.
     * @param string $code Código o número mostrado de la entidad.
     * @param string $counterparty Contraparte (proveedor o empleado).
     * @param string $summary Resumen (monto o tipo de novedad).
     * @param string $status Estado normalizado (slug interno).
     * @param \Cake\I18n\DateTime $assignedAt Fecha de asignación al aprobador.
     * @param \Cake\I18n\DateTime|null $respondedAt Fecha de respuesta, o null si está pendiente.
     * @param string|null $observations Observaciones del aprobador, si las hay.
     */
    public function __construct(
        public string $module,
        public int $entityId,
        public string $code,
        public string $counterparty,
        public string $summary,
        public string $status,
        public DateTime $assignedAt,
        public ?DateTime $respondedAt,
        public ?string $observations,
    ) {
    }
}

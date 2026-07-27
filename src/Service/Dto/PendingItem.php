<?php
declare(strict_types=1);

namespace App\Service\Dto;

use Cake\I18n\DateTime;

/**
 * Representación normalizada de un pendiente de cualquier módulo de flujo para
 * la bandeja "Mis Pendientes". Inmutable.
 */
final readonly class PendingItem
{
    /**
     * @param string $module Slug interno del módulo (invoices, advances, legalizations, petty_cash, refunds, novelties, liquidations, payment_schedulings).
     * @param int $entityId Id de la entidad destino del enlace.
     * @param string $code Código/número legible.
     * @param string $counterparty Contraparte (proveedor/empleado/responsable).
     * @param string $summary Resumen (monto formateado o tipo).
     * @param string $status Slug del estado a mostrar del pipeline del módulo.
     * @param \Cake\I18n\DateTime $date Fecha de creación (clave de orden cross-módulo).
     */
    public function __construct(
        public string $module,
        public int $entityId,
        public string $code,
        public string $counterparty,
        public string $summary,
        public string $status,
        public DateTime $date,
    ) {
    }
}

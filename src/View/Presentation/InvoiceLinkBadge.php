<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * Badge de vinculación de una factura con otro módulo.
 *
 * `isContainment = true`  → el registro padre gobierna el pipeline de la factura
 *                           (caja menor, reintegro, anticipo). El badge REEMPLAZA
 *                           la pipeline-mini de la fila.
 * `isContainment = false` → referencia: solo agenda el pago (programación). El
 *                           badge ACOMPAÑA a la pipeline-mini.
 */
final readonly class InvoiceLinkBadge
{
    /**
     * @param string $code Código del registro padre.
     * @param string $label Etiqueta del vínculo.
     * @param string $icon Icono del badge.
     * @param bool $isContainment El padre gobierna el pipeline de la factura.
     * @param string $controller Controller del registro padre.
     * @param int $parentId ID del registro padre.
     * @param string $pillClass Clase de pill del badge.
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $icon,
        public bool $isContainment,
        public string $controller,
        public int $parentId,
        public string $pillClass,
    ) {
    }
}

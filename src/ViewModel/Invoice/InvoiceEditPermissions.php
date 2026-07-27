<?php
declare(strict_types=1);

namespace App\ViewModel\Invoice;

/**
 * Bundle de capacidades (rol × estado × entidad) que aplican al edit de una
 * factura. El VM las desempaca a propiedades planas para preservar la API
 * que consume el template.
 */
final readonly class InvoiceEditPermissions
{
    /**
     * @param bool $canAdvance Puede avanzar la factura al siguiente paso.
     * @param bool $canDeleteDocuments Puede eliminar documentos del pipeline.
     * @param bool $canRegress Puede regresar la factura al paso anterior.
     * @param bool $canConfirmPayment Puede confirmar la ejecución del pago.
     * @param bool $canRegisterPayment Puede registrar un nuevo pago.
     * @param bool $canAuthorizePayment Puede autorizar un pago pendiente.
     * @param bool $isRejected La factura fue rechazada por el área.
     * @param bool $isApproved La factura fue aprobada por el área.
     */
    public function __construct(
        public bool $canAdvance,
        public bool $canDeleteDocuments,
        public bool $canRegress,
        public bool $canConfirmPayment,
        public bool $canRegisterPayment,
        public bool $canAuthorizePayment,
        public bool $isRejected,
        public bool $isApproved,
    ) {
    }
}

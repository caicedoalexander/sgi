<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Entity\Invoice;

/**
 * Domain event: una factura llegó a `pipeline_status = pagada`.
 *
 * Publishers: InvoicePipelineService::saveAndAdvance (avance manual),
 *             InvoicePaymentService::authorizePayment (autorización de pago completo).
 *
 * Subscriber: LegalizationInitializerSubscriber (filtra por doctype Anticipo).
 */
final readonly class InvoicePaidEvent
{
    /**
     * @param \App\Model\Entity\Invoice $invoice Factura que alcanzó el estado pagada.
     * @param int $actorUserId ID del usuario que disparó la transición.
     */
    public function __construct(
        public Invoice $invoice,
        public int $actorUserId,
    ) {
    }
}

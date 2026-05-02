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
    public function __construct(
        public Invoice $invoice,
        public int $actorUserId,
    ) {
    }
}

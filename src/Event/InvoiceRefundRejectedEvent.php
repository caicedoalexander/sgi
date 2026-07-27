<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Entity\InvoicePayment;

/**
 * Domain event: un pago de tipo refund (`is_refund=true`) fue rechazado.
 *
 * Publisher:  InvoicePaymentService::rejectPayment.
 * Subscriber: RefundOutcomeSubscriber → AdvanceLegalizationService::reopenAfterRefundRejected.
 */
final readonly class InvoiceRefundRejectedEvent
{
    /**
     * @param \App\Model\Entity\InvoicePayment $payment Pago de reintegro rechazado.
     * @param int $actorUserId ID del usuario que rechazó el pago.
     */
    public function __construct(
        public InvoicePayment $payment,
        public int $actorUserId,
    ) {
    }
}

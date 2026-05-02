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
    public function __construct(
        public InvoicePayment $payment,
        public int $actorUserId,
    ) {
    }
}

<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Entity\InvoicePayment;

/**
 * Domain event: un pago de tipo refund (`is_refund=true`) fue autorizado.
 *
 * Publisher:  InvoicePaymentService::authorizePayment.
 * Subscriber: RefundOutcomeSubscriber → AdvanceLegalizationService::closeOnRefundAuthorized.
 */
final readonly class InvoiceRefundAuthorizedEvent
{
    public function __construct(
        public InvoicePayment $payment,
        public int $actorUserId,
    ) {
    }
}

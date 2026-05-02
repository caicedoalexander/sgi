<?php
declare(strict_types=1);

namespace App\Service\Subscriber;

use App\Event\InvoiceRefundAuthorizedEvent;
use App\Event\InvoiceRefundRejectedEvent;
use App\Event\ListenerFailedException;
use App\Service\AdvanceLegalizationService;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;

/**
 * Reacciona a los eventos de outcome de pagos refund (sobrante de anticipo):
 * - Invoice.refundAuthorized → AdvanceLegalizationService::closeOnRefundAuthorized
 * - Invoice.refundRejected   → AdvanceLegalizationService::reopenAfterRefundRejected
 *
 * Cualquier fail del servicio interno se eleva como ListenerFailedException
 * para que el transactional() del publisher haga rollback de la operación
 * (autorización/rechazo del pago) que disparó el evento.
 */
final class RefundOutcomeSubscriber implements EventListenerInterface
{
    public function __construct(
        private readonly AdvanceLegalizationService $legalizationService,
    ) {
    }

    public function implementedEvents(): array
    {
        return [
            'Invoice.refundAuthorized' => 'onRefundAuthorized',
            'Invoice.refundRejected' => 'onRefundRejected',
        ];
    }

    public function onRefundAuthorized(EventInterface $event): void
    {
        /** @var \App\Event\InvoiceRefundAuthorizedEvent $payload */
        $payload = $event->getData('payload');
        if (!$payload instanceof InvoiceRefundAuthorizedEvent) {
            return;
        }

        $result = $this->legalizationService->closeOnRefundAuthorized(
            $payload->payment->id,
            $payload->actorUserId,
        );
        if (!$result->success) {
            throw new ListenerFailedException(
                'RefundOutcomeSubscriber: closeOnRefundAuthorized falló: '
                . implode(', ', (array)$result->errors),
            );
        }
    }

    public function onRefundRejected(EventInterface $event): void
    {
        /** @var \App\Event\InvoiceRefundRejectedEvent $payload */
        $payload = $event->getData('payload');
        if (!$payload instanceof InvoiceRefundRejectedEvent) {
            return;
        }

        $result = $this->legalizationService->reopenAfterRefundRejected(
            $payload->payment->id,
            $payload->actorUserId,
        );
        if (!$result->success) {
            throw new ListenerFailedException(
                'RefundOutcomeSubscriber: reopenAfterRefundRejected falló: '
                . implode(', ', (array)$result->errors),
            );
        }
    }
}

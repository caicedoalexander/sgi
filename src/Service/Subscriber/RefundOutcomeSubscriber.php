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
    /**
     * @param \App\Service\AdvanceLegalizationService $legalizationService Servicio de legalización de anticipos.
     */
    public function __construct(
        private readonly AdvanceLegalizationService $legalizationService,
    ) {
    }

    /**
     * Mapea los eventos suscritos a sus handlers.
     *
     * @return array
     */
    public function implementedEvents(): array
    {
        return [
            'Invoice.refundAuthorized' => 'onRefundAuthorized',
            'Invoice.refundRejected' => 'onRefundRejected',
        ];
    }

    /**
     * Handler de Invoice.refundAuthorized: cierra la legalización al autorizar el sobrante.
     *
     * @param \Cake\Event\EventInterface $event Evento con el payload InvoiceRefundAuthorizedEvent.
     * @return void
     */
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

    /**
     * Handler de Invoice.refundRejected: reabre la legalización al rechazar el sobrante.
     *
     * @param \Cake\Event\EventInterface $event Evento con el payload InvoiceRefundRejectedEvent.
     * @return void
     */
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

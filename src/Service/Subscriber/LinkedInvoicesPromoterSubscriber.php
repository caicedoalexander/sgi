<?php
declare(strict_types=1);

namespace App\Service\Subscriber;

use App\Event\AdvanceLegalizedEvent;
use App\Service\Pipeline\Invoice\LinkedInvoiceLegalizer;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;

/**
 * Reacciona a AdvanceLegalization.legalized: promueve las facturas tipo
 * Legalización vinculadas al Anticipo (en estado contabilidad) a `legalizada`.
 *
 * Delega 100% en LinkedInvoiceLegalizer. Cualquier error de DB durante la
 * promoción provoca el rollback del transactional() interno del legalizer
 * y elevará la excepción al publisher (AdvanceLegalizationService::_setStatus).
 */
final class LinkedInvoicesPromoterSubscriber implements EventListenerInterface
{
    public function __construct(
        private readonly LinkedInvoiceLegalizer $legalizer,
    ) {
    }

    public function implementedEvents(): array
    {
        return ['AdvanceLegalization.legalized' => 'onLegalized'];
    }

    public function onLegalized(EventInterface $event): void
    {
        /** @var \App\Event\AdvanceLegalizedEvent $payload */
        $payload = $event->getData('payload');
        if (!$payload instanceof AdvanceLegalizedEvent) {
            return;
        }

        $this->legalizer->legalizeFor(
            $payload->legalization->advance_invoice_id,
            $payload->actorUserId,
        );
    }
}

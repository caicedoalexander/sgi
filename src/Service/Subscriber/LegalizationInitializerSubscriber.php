<?php
declare(strict_types=1);

namespace App\Service\Subscriber;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Event\InvoicePaidEvent;
use App\Event\ListenerFailedException;
use App\Service\AdvanceLegalizationService;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;

/**
 * Reacciona a Invoice.paid y, si la doctype policy lo dispara
 * (típicamente Anticipo→pagada), inicializa la legalización del anticipo.
 *
 * Si AdvanceLegalizationService::initialize devuelve fail, lanza
 * ListenerFailedException para que el transactional() del publisher haga
 * rollback. Esto cierra el hueco de "best-effort silencioso" que existía
 * antes (cf. comentario L151 del antiguo InvoicePaymentService::authorizePayment).
 */
final class LegalizationInitializerSubscriber implements EventListenerInterface
{
    /**
     * @param \App\Service\AdvanceLegalizationService $legalizationService Servicio de legalización de anticipos.
     * @param \App\Service\Pipeline\Invoice\DocumentTypePolicyFactory $documentTypePolicies Factory de policies por tipo de documento.
     */
    public function __construct(
        private readonly AdvanceLegalizationService $legalizationService,
        private readonly DocumentTypePolicyFactory $documentTypePolicies,
    ) {
    }

    /**
     * Mapea los eventos suscritos a sus handlers.
     *
     * @return array
     */
    public function implementedEvents(): array
    {
        return ['Invoice.paid' => 'onInvoicePaid'];
    }

    /**
     * Handler de Invoice.paid: inicializa la legalización del anticipo si la policy lo dispara.
     *
     * @param \Cake\Event\EventInterface $event Evento con el payload InvoicePaidEvent.
     * @return void
     */
    public function onInvoicePaid(EventInterface $event): void
    {
        /** @var \App\Event\InvoicePaidEvent $payload */
        $payload = $event->getData('payload');
        if (!$payload instanceof InvoicePaidEvent) {
            return;
        }

        $invoice = $payload->invoice;
        $policy = $this->documentTypePolicies->for($invoice->document_type ?? null);

        $statusEnum = PipelineStatus::tryFrom((string)$invoice->pipeline_status);
        if ($statusEnum === null || !$policy->triggersAutoLegalization($statusEnum)) {
            return;
        }

        $result = $this->legalizationService->initialize($invoice, $payload->actorUserId);
        if (!$result->success) {
            throw new ListenerFailedException(
                'LegalizationInitializerSubscriber: initialize falló: '
                . implode(', ', (array)$result->errors),
            );
        }
    }
}

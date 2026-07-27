<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Entity\AdvanceLegalization;

/**
 * Domain event: una legalización de anticipo llegó a `status = legalizada`.
 *
 * Publisher:  AdvanceLegalizationService::_setStatus.
 * Subscriber: LinkedInvoicesPromoterSubscriber → LinkedInvoiceLegalizer::legalizeFor.
 */
final readonly class AdvanceLegalizedEvent
{
    /**
     * @param \App\Model\Entity\AdvanceLegalization $legalization Legalización que alcanzó el estado terminal.
     * @param int $actorUserId ID del usuario que disparó la transición.
     */
    public function __construct(
        public AdvanceLegalization $legalization,
        public int $actorUserId,
    ) {
    }
}

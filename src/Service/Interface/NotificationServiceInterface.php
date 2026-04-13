<?php
declare(strict_types=1);

namespace App\Service\Interface;

use App\Model\Entity\Invoice;

interface NotificationServiceInterface
{
    /**
     * Send notification for an invoice status change.
     *
     * @param \App\Model\Entity\Invoice $invoice The invoice entity.
     * @param string $fromStatus Previous status.
     * @param string $toStatus New status.
     * @return array With 'sent' count and 'failed' array.
     */
    public function sendStatusChangeNotification(Invoice $invoice, string $fromStatus, string $toStatus): array;
}

<?php
declare(strict_types=1);

namespace App\Service\Interface;

interface HistoryServiceInterface
{
    /**
     * Record a status change for an entity.
     *
     * @param int $entityId The entity ID.
     * @param string $fromStatus Previous status.
     * @param string $toStatus New status.
     * @param int $userId The user who made the change.
     * @return void
     */
    public function recordStatusChange(int $entityId, string $fromStatus, string $toStatus, int $userId): void;
}

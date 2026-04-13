<?php
declare(strict_types=1);

namespace App\Service\Strategy;

interface ApprovalStrategyInterface
{
    /**
     * Apply an approval action to an entity.
     *
     * @param int $entityId The entity ID.
     * @param string $action The action (approve/reject).
     * @param string|null $observations Optional observations.
     * @param int|null $createdBy User ID.
     * @param string|null $approvalDate Optional approval date.
     * @return bool
     */
    public function apply(
        int $entityId,
        string $action,
        ?string $observations,
        ?int $createdBy,
        ?string $approvalDate = null,
    ): bool;

    /**
     * Get the entity with its associations.
     *
     * @param int $entityId The entity ID.
     * @return object|null
     */
    public function getEntity(int $entityId): ?object;
}

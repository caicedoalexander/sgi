<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\NoveltyLiquidationDoc;
use App\Model\Entity\User;

/**
 * Datos inmutables de vista para NoveltyLiquidationDocsController::edit().
 */
final class NoveltyLiquidationDocEditViewModel
{
    /**
     * @param array<string> $groupErrors
     * @param array<string> $effectiveStatuses
     * @param array<string, mixed> $documentsByStatus
     * @param array<int, string> $bankingEntities
     */
    public function __construct(
        public readonly NoveltyLiquidationDoc $doc,
        public readonly string $roleName,
        public readonly array $groupErrors,
        public readonly array $effectiveStatuses,
        public readonly array $documentsByStatus,
        public readonly mixed $liquidationDocument,
        public readonly User $currentUser,
        public readonly bool $skipsGdp,
        public readonly array $bankingEntities,
        public readonly bool $canRegisterPayment,
        public readonly bool $canAuthorizePayment,
        public readonly bool $canConfirmPayment,
    ) {
    }
}

<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\AssetConstants;
use App\Model\Entity\Asset;
use App\View\Presentation\AssetPresentation;

/**
 * Agregado per-request para AssetsController::view(). Deriva el badge de estado
 * de AssetPresentation (dirección VM → Presentation; Presentation nunca importa
 * este VM).
 */
final readonly class AssetViewViewModel
{
    /**
     * Pareja [label, pillClass] para el badge del estado actual.
     *
     * @var array{0:string, 1:string}
     */
    public array $currentStatusBadge;

    /**
     * @param array<int, \App\Model\Entity\AssetMovement> $movements
     * @param array<int, \App\Model\Entity\AssetDocument> $documents
     * @param iterable<\App\Model\Entity\Employee>        $employees
     * @param iterable<\App\Model\Entity\OperationCenter> $operationCenters
     */
    public function __construct(
        public Asset $asset,
        public array $movements,
        public array $documents,
        public bool $canEdit,
        public bool $canDelete,
        public bool $canCreateMovement,
        public iterable $employees,
        public iterable $operationCenters,
    ) {
        $status = $asset->status ?? '';
        $this->currentStatusBadge = [
            AssetConstants::STATUS_LABELS[$status] ?? $status,
            AssetPresentation::STATUS_BADGES[$status] ?? 'pill-secondary-soft',
        ];
    }
}

<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\ApprovalInboxConstants;
use App\View\Presentation\ApprovalInboxPresentation;

/**
 * Datos inmutables de presentación para ApprovalsController::view(). Agrega la
 * entidad de dominio (factura o novedad) más el estado normalizado y las
 * observaciones del aprobador. Dirección de dependencia VM → Presentation.
 */
final readonly class ApprovalViewViewModel implements ViewViewModelInterface
{
    public string $pageTitle;
    /**
     * @var array{0:string,1:string}
     */
    public array $currentStatusBadge;
    public string $currentStatus;

    /**
     * @param string $module Módulo de origen (factura o novedad).
     * @param object $entity Entidad de dominio aprobable.
     * @param string $status Estado de aprobación normalizado.
     * @param string|null $observations Observaciones del aprobador.
     */
    public function __construct(
        public string $module,
        public object $entity,
        string $status,
        public ?string $observations,
    ) {
        $this->currentStatus = $status;
        $this->currentStatusBadge = [
            ApprovalInboxConstants::STATUS_LABELS[$status] ?? $status,
            ApprovalInboxPresentation::STATUS_BADGES[$status] ?? 'pill-muted',
        ];
        $this->pageTitle = $this->buildTitle($module, $entity);
    }

    /**
     * Construye el título legible de la página según el módulo de la entidad.
     *
     * @param string $module Módulo de origen (factura o novedad).
     * @param object $entity Entidad de dominio aprobable.
     * @return string
     */
    private function buildTitle(string $module, object $entity): string
    {
        if ($module === ApprovalInboxConstants::MODULE_INVOICE) {
            return 'Factura ' . (string)($entity->invoice_number ?? '#' . ($entity->id ?? ''));
        }

        return 'Novedad #' . (string)($entity->id ?? '');
    }
}

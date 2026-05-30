<?php
declare(strict_types=1);

namespace App\ViewModel;

/**
 * Contrato común de los EditViewModels (Invoice, Refund, PettyCash,
 * Novelty, NoveltyLiquidationDoc, PaymentScheduling). Garantiza que los
 * templates de edit puedan asumir la presencia de estos 4 datos canónicos
 * sin tipar contra una clase concreta.
 *
 * Implementado por propiedades promovidas/declaradas en cada VM (clases
 * `final readonly class`). Una propiedad readonly satisface el accesor
 * `{ get; }` del interface.
 */
interface EditViewModelInterface
{
    /** Título de la página (header del browser y/o de la vista). */
    public string $pageTitle { get; }

    /** @var array{0:string,1:string} Pareja [label, class-Bootstrap] para el badge del estado actual. */
    public array $currentStatusBadge { get; }

    /** Nombre del rol del usuario actual (para gating visual). */
    public string $roleName { get; }

    /** Slug del estado actual del pipeline. */
    public string $currentStatus { get; }
}

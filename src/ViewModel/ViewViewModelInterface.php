<?php
declare(strict_types=1);

namespace App\ViewModel;

/**
 * Contrato común de los ViewModels de la acción `view` (solo lectura) de los
 * módulos de flujo. Espejo read-only de {@see EditViewModelInterface}: garantiza
 * que los templates de detalle puedan asumir la presencia de estos 3 datos
 * canónicos sin tipar contra una clase concreta.
 *
 * Implementado por propiedades promovidas/declaradas en cada VM (clases
 * `final readonly class`). Una propiedad readonly satisface el accesor
 * `{ get; }` del interface.
 */
interface ViewViewModelInterface
{
    /**
     * Título de la página (header del browser y/o de la vista).
     */
    public string $pageTitle { get; }

    /**
     * @var array{0:string,1:string} Pareja [label, class-pill] para el badge del estado actual.
     */
    public array $currentStatusBadge { get; }

    /**
     * Slug del estado actual del pipeline.
     */
    public string $currentStatus { get; }
}

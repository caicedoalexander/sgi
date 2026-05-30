<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * DTO inmutable con la derivación de estado de una fila del listado de novedades
 * (EmployeeNovelties/index, vista lista). Producido por
 * NoveltyPresentation::forEmployeeNoveltyRow().
 *
 * Solo absorbe estado/pill/label — el resto de la fila (color de tipo, período,
 * días, avatar) usa closures de la vista y se queda en el template.
 */
final readonly class EmployeeNoveltyRowView
{
    public function __construct(
        public bool   $isRejected,
        public bool   $isPaid,
        public string $statusLabel,
        public string $statusBadgeClass,
    ) {
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

/**
 * Polymorphic representation of one pipeline state.
 *
 * Cada State conoce su transición natural, qué roles lo ven y qué requisitos
 * verifica para avanzar. NO conoce el doctype (eso es de DocumentTypePolicy)
 * ni los locks (eso es de InvoiceLockPolicy).
 */
interface InvoicePipelineState
{
    /** Nombre canónico del estado (e.g. 'aprobacion'). */
    public function getName(): string;

    /** Estado siguiente "natural"; null si terminal. La DocumentTypePolicy puede bloquearlo. */
    public function getNext(): ?string;

    /** Estado anterior; null si es el primero. */
    public function getPrevious(): ?string;

    /**
     * Roles (RoleConstants::*) que ven este estado en el index principal.
     *
     * @return array<string>
     */
    public function getRoleVisibility(): array;

    /**
     * Roles que ven este estado en "Mis Anticipos".
     *
     * @return array<string>
     */
    public function getAdvanceRoleVisibility(): array;

    /**
     * Errores de requirement de este estado para avanzar al siguiente.
     * No incluye rejection ni doctype block — el coordinador los compone.
     *
     * @return array<string>
     */
    public function validateAdvance(object $invoice): array;

    /**
     * Reglas crudas (campo + etiqueta) para UI. No evalúa contra el invoice.
     *
     * @return array<int, array{field: string, label: string}>
     */
    public function getTransitionRules(): array;
}

<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\Pipeline\DocumentTypePolicyFactory;
use App\Service\Pipeline\InvoicePipelineStateRegistry;

/**
 * Orquesta la validación de avance del pipeline:
 *  - rejection bloquea todo avance,
 *  - DocumentTypePolicy puede bloquear con un mensaje (Legalización en contabilidad),
 *  - el State del estado actual valida sus requirements.
 *
 * También filtra los errores que un rol puede resolver desde el formulario.
 */
final class InvoiceTransitionValidator
{
    /** Mapeo requirement-field → campos del form que lo resuelven. */
    private const REQUIREMENT_FIELDS = [
        'area_approval'        => [],
        'dian_validation'      => ['dian_validation'],
        'accrued'              => ['accrued', 'accrual_date'],
        'accrual_date'         => ['accrual_date'],
        'ready_for_payment'    => ['ready_for_payment'],
        '_has_pending_payment' => [],
        '_payment_authorized'  => [],
    ];

    public function __construct(
        private readonly InvoicePipelineStateRegistry $states,
        private readonly DocumentTypePolicyFactory $policies,
        private readonly InvoiceFieldAccessPolicy $fieldPolicy,
    ) {
    }

    /**
     * Errores de avance: rejection + doctype block + state validation.
     *
     * @return array<string>
     */
    public function validateAdvance(object $invoice, string $fromStatus): array
    {
        if (($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED) {
            return ['La factura fue rechazada. El flujo ha terminado.'];
        }

        $state = $this->states->get($fromStatus);
        $policy = $this->policies->for($invoice->document_type ?? null);

        $blockMsg = $policy->blocksAdvance($state, $invoice);
        if ($blockMsg !== null) {
            return [$blockMsg];
        }

        return $state->validateAdvance($invoice);
    }

    /**
     * @return array<int, array{field: string, label: string}>
     */
    public function getTransitionRules(string $fromStatus): array
    {
        return $this->states->get($fromStatus)->getTransitionRules();
    }

    /**
     * @param array<string> $errors
     * @param array<int, array{field: string, label: string}> $rules
     * @return array<string>
     */
    public function filterErrorsForRole(array $errors, array $rules, int $roleId, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return array_values($errors);
        }

        $editable = $this->fieldPolicy->getEditableFields($roleId, $roleName, $status);
        $statusVisible = in_array($roleName, $this->states->get($status)->getRoleVisibility(), true);

        $filtered = [];
        foreach ($rules as $i => $rule) {
            if (!isset($errors[$i])) {
                continue;
            }
            $field = $rule['field'];
            $responsible = self::REQUIREMENT_FIELDS[$field] ?? [$field];

            if ($responsible === []) {
                if ($statusVisible) {
                    $filtered[] = $errors[$i];
                }
                continue;
            }

            if (array_intersect($responsible, $editable)) {
                $filtered[] = $errors[$i];
            }
        }

        return $filtered;
    }
}

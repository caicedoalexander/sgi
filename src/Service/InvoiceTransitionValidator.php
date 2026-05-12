<?php
declare(strict_types=1);

namespace App\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\InvoicePipelineStateRegistry;
use App\ValueObject\UserContext;

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
        private readonly AuthorizationFacade $auth,
    ) {
    }

    /**
     * Errores de avance: rejection + doctype block + state validation.
     *
     * @param array<string, mixed> $overrides Campos pendientes de guardar a evaluar como si ya estuvieran en el invoice.
     * @return array<string>
     */
    public function validateAdvance(object $invoice, string $fromStatus, array $overrides = []): array
    {
        $subject = $invoice;
        if (!empty($overrides)) {
            $subject = clone $invoice;
            foreach ($overrides as $field => $value) {
                $subject->$field = $value;
            }
        }

        if (($subject->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED) {
            return ['La factura fue rechazada. El flujo ha terminado.'];
        }

        $fromEnum = PipelineStatus::tryFrom($fromStatus);
        if ($fromEnum === null) {
            return ["Estado de origen inválido: {$fromStatus}"];
        }

        $state = $this->states->get($fromEnum);
        $policy = $this->policies->for($subject->document_type ?? null);

        $blockMsg = $policy->blocksAdvance($state, $subject);
        if ($blockMsg !== null) {
            return [$blockMsg];
        }

        return $state->validateAdvance($subject);
    }

    /**
     * @return array<int, array{field: string, label: string}>
     */
    public function getTransitionRules(string $fromStatus): array
    {
        $fromEnum = PipelineStatus::tryFrom($fromStatus);
        if ($fromEnum === null) {
            return [];
        }

        return $this->states->get($fromEnum)->getTransitionRules();
    }

    /**
     * @param array<string> $errors
     * @param array<int, array{field: string, label: string}> $rules
     * @return array<string>
     */
    public function filterErrorsForRole(array $errors, array $rules, int $roleId, string $status): array
    {
        $editable = $this->fieldPolicy->getEditableFields($roleId, $status);
        $statusVisible = $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_INVOICES,
            $status,
        );

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

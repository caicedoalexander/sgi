<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\Policy;

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
    /**
     * Mapeo requirement-key → campos del form que lo resuelven. Responsable `[]`
     * significa "nadie lo resuelve tecleando un campo del form" (se resuelve
     * aprobando, subiendo un documento o registrando un pago): su visibilidad la
     * gobierna `canOperate` del status.
     */
    private const REQUIREMENT_FIELDS = [
        'area_approval' => [],
        'dian_validation' => ['dian_validation'],
        'support_document' => [],
        'accrued' => ['accrued', 'accrual_date'],
        'accrual_date' => ['accrual_date'],
        'ready_for_payment' => ['ready_for_payment'],
        '_has_pending_payment' => [],
        '_payment_authorized' => [],
        '_payment_executed' => [],
    ];

    /** Keys compuestas por el propio validator: no las resuelve ningún campo, siempre se muestran. */
    private const ALWAYS_VISIBLE_KEYS = ['_rejected', '_doctype_block', '_invalid_status'];

    /**
     * @param \App\Service\Pipeline\Invoice\InvoicePipelineStateRegistry $states Registro de States del pipeline.
     * @param \App\Service\Pipeline\Invoice\DocumentTypePolicyFactory $policies Factory de policies por document_type.
     * @param \App\Service\Pipeline\Invoice\Policy\InvoiceFieldAccessPolicy $fieldPolicy Policy de campos editables por paso.
     * @param \App\Authorization\AuthorizationFacade $auth Authorization facade.
     */
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
     * @return array<string, string> Errores keyed por requisito.
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
            return ['_rejected' => 'La factura fue rechazada. El flujo ha terminado.'];
        }

        $fromEnum = PipelineStatus::tryFrom($fromStatus);
        if ($fromEnum === null) {
            return ['_invalid_status' => "Estado de origen inválido: {$fromStatus}"];
        }

        $state = $this->states->get($fromEnum);
        $policy = $this->policies->for($subject->document_type ?? null);

        $blockMsg = $policy->blocksAdvance($state, $subject);
        if ($blockMsg !== null) {
            return ['_doctype_block' => $blockMsg];
        }

        return $state->validateAdvance($subject);
    }

    /**
     * @param array<string, string> $errors Errores keyed por requisito.
     * @return array<string>
     */
    public function filterErrorsForRole(array $errors, int $roleId, string $status): array
    {
        $editable = $this->fieldPolicy->getEditableFields($roleId, $status);
        $statusVisible = $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_INVOICES,
            $status,
        );

        $filtered = [];
        foreach ($errors as $key => $message) {
            if (in_array($key, self::ALWAYS_VISIBLE_KEYS, true)) {
                $filtered[] = $message;
                continue;
            }
            $responsible = self::REQUIREMENT_FIELDS[$key] ?? [$key];

            if ($responsible === []) {
                if ($statusVisible) {
                    $filtered[] = $message;
                }
                continue;
            }

            if (array_intersect($responsible, $editable)) {
                $filtered[] = $message;
            }
        }

        return $filtered;
    }
}

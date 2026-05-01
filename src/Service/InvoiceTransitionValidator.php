<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;

/**
 * Orquesta la validación de avance del pipeline:
 *  - rejection bloquea todo avance,
 *  - regla doctype-specific (Legalización en contabilidad bloquea con mensaje),
 *  - chequeo de requirements del estado actual.
 *
 * También filtra los errores que un rol puede resolver desde el formulario.
 *
 * Esta clase tiene dos vidas:
 *  - Task 2 (este task): trabaja con las constantes TRANSITION_REQUIREMENTS
 *    inyectadas por el coordinador. No conoce States ni DocumentTypePolicy aún.
 *  - Task 5: pasa a depender de InvoicePipelineStateRegistry y
 *    DocumentTypePolicyFactory; las constantes legacy desaparecen del coordinador.
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

    /** Reglas de transición indexadas por estado origen. */
    private const TRANSITION_REQUIREMENTS = [
        InvoiceConstants::STATUS_APROBACION => [
            [
                'field' => 'area_approval',
                'value' => InvoiceConstants::APPROVAL_APPROVED,
                'label' => 'Todos los aprobadores deben haber aprobado',
            ],
            [
                'field' => 'dian_validation',
                'value' => InvoiceConstants::DIAN_APPROVED,
                'label' => 'Validación DIAN debe ser "Aprobada"',
            ],
        ],
        InvoiceConstants::STATUS_CONTABILIDAD => [
            [
                'field' => 'accrued',
                'value' => true,
                'label' => 'La factura debe estar marcada como Causada',
            ],
            [
                'field' => 'accrual_date',
                'not_empty' => true,
                'label' => 'Fecha de Causación es requerida',
            ],
            [
                'field' => 'ready_for_payment',
                'not_empty' => true,
                'label' => 'Campo "Lista para Pago" es requerido',
            ],
        ],
        InvoiceConstants::STATUS_TESORERIA => [
            [
                'field' => '_has_pending_payment',
                'custom' => true,
                'label' => 'Debe registrar al menos un pago para avanzar a autorización',
            ],
        ],
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => [
            [
                'field' => '_payment_authorized',
                'custom' => true,
                'label' => 'El pago pendiente debe ser autorizado por el Contador',
            ],
        ],
    ];

    public function __construct(
        private readonly InvoicePaymentService $paymentService,
        private readonly InvoiceFieldAccessPolicy $fieldPolicy,
    ) {
    }

    /**
     * Errores de avance: rejection + doctype block (Legalización en contabilidad)
     * + requirements del estado.
     *
     * @param object $invoice Invoice (puede ser entidad parchada en saveAndAdvance).
     * @param string $fromStatus Estado desde el que se intenta avanzar.
     * @return array<string>
     */
    public function validateAdvance(object $invoice, string $fromStatus): array
    {
        if (($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED) {
            return ['La factura fue rechazada. El flujo ha terminado.'];
        }

        if (
            ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_LEGALIZACION
            && $fromStatus === InvoiceConstants::STATUS_CONTABILIDAD
        ) {
            return ['La legalización avanzará automáticamente cuando el Anticipo padre se legalice.'];
        }

        $errors = [];
        foreach (self::TRANSITION_REQUIREMENTS[$fromStatus] ?? [] as $rule) {
            if (!empty($rule['custom'])) {
                if ($rule['field'] === '_has_pending_payment') {
                    if (!$this->paymentService->hasPendingAuthorization($invoice->id)) {
                        $errors[] = $rule['label'];
                    }
                } elseif ($rule['field'] === '_payment_authorized') {
                    if ($this->paymentService->hasPendingAuthorization($invoice->id)) {
                        $errors[] = $rule['label'];
                    }
                }
                continue;
            }

            $field = $rule['field'];
            $value = $invoice->$field ?? null;

            if (isset($rule['value'])) {
                $expected = $rule['value'];
                $actual = is_bool($expected) ? (bool)$value : $value;
                if ($actual !== $expected) {
                    $errors[] = $rule['label'];
                }
            } elseif (!empty($rule['not_empty'])) {
                if ($value === null || $value === '' || $value === false) {
                    $errors[] = $rule['label'];
                }
            }
        }

        return $errors;
    }

    /**
     * Reglas crudas para UI (sin evaluar).
     *
     * @return array<int, array{field: string, label: string}>
     */
    public function getTransitionRules(string $fromStatus): array
    {
        $rules = [];
        foreach (self::TRANSITION_REQUIREMENTS[$fromStatus] ?? [] as $rule) {
            $rules[] = ['field' => $rule['field'], 'label' => $rule['label']];
        }

        return $rules;
    }

    /**
     * Filtra los errores que un rol puede resolver desde el formulario.
     *
     * @param array<string> $errors
     * @param array<int, array{field: string, label: string}> $rules
     * @return array<string>
     */
    public function filterErrorsForRole(array $errors, array $rules, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return array_values($errors);
        }

        $editable = $this->fieldPolicy->getEditableFields($roleName, $status);
        $visibleStatuses = $this->getVisibleStatusesForRole($roleName);
        $statusVisible = in_array($status, $visibleStatuses, true);

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

    /**
     * Helper local hasta que Task 5 conecte el StateRegistry.
     * Replica el mapeo de ROLE_VISIBLE_STATUSES del coordinador.
     */
    private function getVisibleStatusesForRole(string $roleName): array
    {
        return match ($roleName) {
            RoleConstants::REGISTRO_REVISION => [InvoiceConstants::STATUS_APROBACION],
            RoleConstants::CONTABILIDAD      => [InvoiceConstants::STATUS_CONTABILIDAD],
            RoleConstants::TESORERIA         => [InvoiceConstants::STATUS_TESORERIA, InvoiceConstants::STATUS_AUTORIZACION_PAGO],
            RoleConstants::CONTADOR          => [InvoiceConstants::STATUS_AUTORIZACION_PAGO],
            RoleConstants::ADMIN             => [
                InvoiceConstants::STATUS_APROBACION,
                InvoiceConstants::STATUS_CONTABILIDAD,
                InvoiceConstants::STATUS_TESORERIA,
                InvoiceConstants::STATUS_AUTORIZACION_PAGO,
                InvoiceConstants::STATUS_PAGADA,
                InvoiceConstants::STATUS_LEGALIZADA,
            ],
            default => [],
        };
    }
}

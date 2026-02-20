<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RoleConstants;

class InvoicePipelineService
{
    // Pipeline statuses in order
    public const STATUSES = ['revision', 'area_approved', 'accrued', 'treasury', 'paid'];

    public const STATUS_LABELS = [
        'revision' => 'Revisión',
        'area_approved' => 'Área Aprobada',
        'accrued' => 'Causada',
        'treasury' => 'Tesorería',
        'paid' => 'Pagada',
    ];

    public const STATUS_ICONS = [
        'revision' => 'bi-search',
        'area_approved' => 'bi-check-circle',
        'accrued' => 'bi-calculator',
        'treasury' => 'bi-bank',
        'paid' => 'bi-cash-coin',
    ];

    // Which statuses each role can see/work with
    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::REGISTRO_REVISION => ['revision'],
        RoleConstants::CONTABILIDAD      => ['area_approved', 'accrued'],
        RoleConstants::TESORERIA         => ['treasury'],
        RoleConstants::ADMIN             => ['revision', 'area_approved', 'accrued', 'treasury', 'paid'],
    ];

    // All fields available for Admin in any status
    private const ALL_FIELDS = [
        'invoice_number', 'registration_date', 'issue_date', 'due_date',
        'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
        'detail', 'amount', 'expense_type_id', 'cost_center_id',
        'confirmed_by', 'approver_id', 'area_approval', 'area_approval_date',
        'dian_validation', 'accrued', 'accrual_date', 'ready_for_payment',
        'payment_status', 'payment_date', 'pipeline_status', 'observations',
    ];

    // Fields editable by role in each status
    private const EDITABLE_FIELDS = [
        RoleConstants::REGISTRO_REVISION => [
            'revision' => [
                'invoice_number', 'registration_date', 'issue_date', 'due_date',
                'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
                'detail', 'amount', 'expense_type_id', 'cost_center_id',
                'confirmed_by', 'approver_id', 'area_approval', 'area_approval_date',
                'dian_validation', 'observations',
            ],
        ],
        RoleConstants::CONTABILIDAD => [
            'area_approved' => [
                'accrued', 'accrual_date', 'ready_for_payment', 'observations',
            ],
            'accrued' => [
                'accrued', 'accrual_date', 'ready_for_payment', 'observations',
            ],
        ],
        RoleConstants::TESORERIA => [
            'treasury' => [
                'payment_status', 'payment_date', 'observations',
            ],
        ],
    ];

    // Sections visible per role (non-Admin roles have fixed sections)
    private const VISIBLE_SECTIONS_BY_ROLE = [
        RoleConstants::REGISTRO_REVISION => ['general', 'dates', 'classification', 'revision'],
        RoleConstants::CONTABILIDAD      => ['general', 'dates', 'classification', 'accounting'],
        RoleConstants::TESORERIA         => ['general', 'treasury'],
    ];

    // Fields required before advancing from each status
    private const TRANSITION_REQUIREMENTS = [
        'revision' => [
            [
                'field' => 'area_approval',
                'value' => 'Aprobada',
                'label' => 'Aprobación de Área debe ser "Aprobada"',
            ],
            [
                'field' => 'dian_validation',
                'value' => 'Aprobada',
                'label' => 'Validación DIAN debe ser "Aprobada"',
            ],
        ],
        'area_approved' => [
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
        ],
        'accrued' => [
            [
                'field' => 'ready_for_payment',
                'not_empty' => true,
                'label' => 'Campo "Lista para Pago" es requerido',
            ],
        ],
        'treasury' => [
            [
                'field' => 'payment_status',
                'value' => 'Pago total',
                'label' => 'Estado de Pago debe ser "Pago total" para marcar como Pagada',
            ],
            [
                'field' => 'payment_date',
                'not_empty' => true,
                'label' => 'Fecha de Pago es requerida',
            ],
        ],
    ];

    // Next status transitions
    public const TRANSITIONS = [
        'revision'     => 'area_approved',
        'area_approved' => 'accrued',
        'accrued'      => 'treasury',
        'treasury'     => 'paid',
        'paid'         => null,
    ];

    public function getVisibleStatuses(string $roleName): array
    {
        return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    public function getEditableFields(string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::ALL_FIELDS;
        }

        return self::EDITABLE_FIELDS[$roleName][$status] ?? [];
    }

    /**
     * Returns sections visible in the edit form for the given role and current status.
     * For non-Admin roles: fixed sections regardless of status.
     * For Admin: sections depend on how far the invoice has progressed.
     */
    public function getVisibleSections(string $roleName, string $status): array
    {
        if ($roleName !== RoleConstants::ADMIN) {
            return self::VISIBLE_SECTIONS_BY_ROLE[$roleName] ?? ['general'];
        }

        // Admin: show sections up to the current state
        $statusIndex = $this->getStatusIndex($status);
        $sections = ['general', 'dates', 'classification', 'revision'];
        if ($statusIndex >= 1) {
            $sections[] = 'accounting';
        }
        if ($statusIndex >= 3) {
            $sections[] = 'treasury';
        }

        return $sections;
    }

    /**
     * Returns true if the invoice has been rejected in the revision step.
     */
    public function isRejected(object $invoice): bool
    {
        return ($invoice->area_approval ?? '') === 'Rechazada';
    }

    /**
     * Validates whether all requirements are met to advance from $fromStatus.
     * Returns an array of error messages (empty = can advance).
     */
    public function validateTransitionRequirements(object $invoice, string $fromStatus): array
    {
        // Rejection at revision blocks all advancement
        if ($fromStatus === 'revision' && $this->isRejected($invoice)) {
            return ['La factura fue rechazada en Revisión. El flujo ha terminado.'];
        }

        $errors = [];
        foreach (self::TRANSITION_REQUIREMENTS[$fromStatus] ?? [] as $rule) {
            $field = $rule['field'];
            $value = $invoice->$field ?? null;

            if (isset($rule['value'])) {
                $expected = $rule['value'];
                if (is_bool($expected)) {
                    $actual = (bool)$value;
                } else {
                    $actual = $value;
                }
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

    public function canAdvance(string $roleName, string $currentStatus): bool
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::TRANSITIONS[$currentStatus] !== null;
        }

        $visibleStatuses = $this->getVisibleStatuses($roleName);
        if (!in_array($currentStatus, $visibleStatuses)) {
            return false;
        }

        return self::TRANSITIONS[$currentStatus] !== null;
    }

    public function getNextStatus(string $currentStatus): ?string
    {
        return self::TRANSITIONS[$currentStatus] ?? null;
    }

    public function filterEntityData(array $data, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return $data;
        }

        $allowed = $this->getEditableFields($roleName, $status);
        return array_intersect_key($data, array_flip($allowed));
    }

    public function getStatusIndex(string $status): int
    {
        $index = array_search($status, self::STATUSES);
        return $index !== false ? $index : 0;
    }
}

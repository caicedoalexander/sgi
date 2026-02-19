<?php
declare(strict_types=1);

namespace App\Service;

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
        'Registro/Revisión' => ['revision'],
        'Contabilidad'      => ['area_approved', 'accrued'],
        'Tesorería'         => ['treasury'],
        'Admin'             => ['revision', 'area_approved', 'accrued', 'treasury', 'paid'],
    ];

    // Fields editable by role in each status
    private const EDITABLE_FIELDS = [
        'Registro/Revisión' => [
            'revision' => [
                'invoice_number', 'registration_date', 'issue_date', 'due_date',
                'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
                'detail', 'amount', 'expense_type_id', 'cost_center_id',
                'confirmed_by', 'approver_id', 'area_approval', 'area_approval_date',
                'dian_validation', 'observations',
            ],
        ],
        'Contabilidad' => [
            'area_approved' => [
                'accrued', 'accrual_date', 'ready_for_payment', 'observations',
            ],
            'accrued' => [
                'accrued', 'accrual_date', 'ready_for_payment', 'observations',
            ],
        ],
        'Tesorería' => [
            'treasury' => [
                'payment_status', 'payment_date', 'observations',
            ],
        ],
        'Admin' => [
            'revision' => [
                'invoice_number', 'registration_date', 'issue_date', 'due_date',
                'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
                'detail', 'amount', 'expense_type_id', 'cost_center_id',
                'confirmed_by', 'approver_id', 'area_approval', 'area_approval_date',
                'dian_validation', 'accrued', 'accrual_date', 'ready_for_payment',
                'payment_status', 'payment_date', 'pipeline_status', 'observations',
            ],
            'area_approved' => [
                'invoice_number', 'registration_date', 'issue_date', 'due_date',
                'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
                'detail', 'amount', 'expense_type_id', 'cost_center_id',
                'confirmed_by', 'approver_id', 'area_approval', 'area_approval_date',
                'dian_validation', 'accrued', 'accrual_date', 'ready_for_payment',
                'payment_status', 'payment_date', 'pipeline_status', 'observations',
            ],
            'accrued' => [
                'invoice_number', 'registration_date', 'issue_date', 'due_date',
                'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
                'detail', 'amount', 'expense_type_id', 'cost_center_id',
                'confirmed_by', 'approver_id', 'area_approval', 'area_approval_date',
                'dian_validation', 'accrued', 'accrual_date', 'ready_for_payment',
                'payment_status', 'payment_date', 'pipeline_status', 'observations',
            ],
            'treasury' => [
                'invoice_number', 'registration_date', 'issue_date', 'due_date',
                'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
                'detail', 'amount', 'expense_type_id', 'cost_center_id',
                'confirmed_by', 'approver_id', 'area_approval', 'area_approval_date',
                'dian_validation', 'accrued', 'accrual_date', 'ready_for_payment',
                'payment_status', 'payment_date', 'pipeline_status', 'observations',
            ],
            'paid' => [
                'invoice_number', 'registration_date', 'issue_date', 'due_date',
                'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
                'detail', 'amount', 'expense_type_id', 'cost_center_id',
                'confirmed_by', 'approver_id', 'area_approval', 'area_approval_date',
                'dian_validation', 'accrued', 'accrual_date', 'ready_for_payment',
                'payment_status', 'payment_date', 'pipeline_status', 'observations',
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
        return self::EDITABLE_FIELDS[$roleName][$status] ?? [];
    }

    public function canAdvance(string $roleName, string $currentStatus): bool
    {
        if ($roleName === 'Admin') {
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
        if ($roleName === 'Admin') {
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

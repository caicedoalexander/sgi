<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;

class InvoiceFieldAccessPolicy
{
    private const ALL_FIELDS = [
        'invoice_number', 'issue_date', 'due_date',
        'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
        'detail', 'amount', 'expense_type_id', 'cost_center_id',
        'confirmed_by', 'approver_id', 'area_approval',
        'dian_validation', 'accrued', 'ready_for_payment',
        'payment_status', 'full_payment_date', 'pipeline_status',
    ];

    private const EDITABLE_FIELDS = [
        RoleConstants::REGISTRO_REVISION => [
            InvoiceConstants::STATUS_APROBACION => [
                'invoice_number', 'issue_date', 'due_date',
                'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
                'detail', 'amount', 'expense_type_id', 'cost_center_id',
                'confirmed_by',
                'dian_validation',
            ],
        ],
        RoleConstants::CONTABILIDAD => [
            InvoiceConstants::STATUS_CONTABILIDAD => [
                'accrued', 'accrual_date', 'ready_for_payment',
            ],
        ],
        RoleConstants::TESORERIA => [
            InvoiceConstants::STATUS_TESORERIA => [],
            InvoiceConstants::STATUS_AUTORIZACION_PAGO => [],
        ],
        RoleConstants::CONTADOR => [
            InvoiceConstants::STATUS_AUTORIZACION_PAGO => [],
        ],
    ];

    private const VISIBLE_SECTIONS_BY_ROLE = [
        RoleConstants::REGISTRO_REVISION => ['general', 'dates', 'classification', 'revision'],
        RoleConstants::CONTABILIDAD      => ['general', 'dates', 'classification', 'accounting'],
        RoleConstants::TESORERIA         => ['general', 'treasury'],
        RoleConstants::CONTADOR          => ['general', 'dates', 'classification', 'revision', 'accounting', 'treasury', 'payment_authorization'],
    ];

    private const COLLAPSIBLE_SECTIONS_BY_ROLE = [
        RoleConstants::REGISTRO_REVISION => [
            InvoiceConstants::STATUS_APROBACION => ['general', 'dates', 'classification'],
        ],
    ];

    public function getEditableFields(string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::ALL_FIELDS;
        }

        return self::EDITABLE_FIELDS[$roleName][$status] ?? [];
    }

    public function getVisibleSections(string $roleName, string $status): array
    {
        if ($roleName !== RoleConstants::ADMIN) {
            return self::VISIBLE_SECTIONS_BY_ROLE[$roleName] ?? ['general'];
        }

        $statusIndex = $this->_getStatusIndex($status);
        $sections = ['general', 'dates', 'classification', 'revision'];
        if ($statusIndex >= 1) {
            $sections[] = 'accounting';
        }
        if ($statusIndex >= 2) {
            $sections[] = 'treasury';
        }
        if ($statusIndex >= 3) {
            $sections[] = 'payment_authorization';
        }

        return $sections;
    }

    public function getCollapsibleSections(string $roleName, string $status): array
    {
        return self::COLLAPSIBLE_SECTIONS_BY_ROLE[$roleName][$status] ?? [];
    }

    public function filterEntityData(array $data, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return $data;
        }

        $allowed = $this->getEditableFields($roleName, $status);

        return array_intersect_key($data, array_flip($allowed));
    }

    private function _getStatusIndex(string $status): int
    {
        $index = array_search($status, InvoiceConstants::PIPELINE_STATUSES);

        return $index !== false ? $index : 0;
    }
}

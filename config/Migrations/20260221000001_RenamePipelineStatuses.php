<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RenamePipelineStatuses extends BaseMigration
{
    public function up(): void
    {
        $statusMap = [
            'revision' => 'registro',
            'area_approved' => 'aprobacion',
            'accrued' => 'contabilidad',
            'treasury' => 'tesoreria',
            'paid' => 'pagada',
        ];

        // Update invoices.pipeline_status
        foreach ($statusMap as $old => $new) {
            $this->execute(
                "UPDATE invoices SET pipeline_status = '{$new}' WHERE pipeline_status = '{$old}'"
            );
        }

        // Update invoice_histories old_value and new_value where field_changed = 'pipeline_status'
        foreach ($statusMap as $old => $new) {
            $this->execute(
                "UPDATE invoice_histories SET old_value = '{$new}' WHERE field_changed = 'pipeline_status' AND old_value = '{$old}'"
            );
            $this->execute(
                "UPDATE invoice_histories SET new_value = '{$new}' WHERE field_changed = 'pipeline_status' AND new_value = '{$old}'"
            );
        }

        // Also update label-based values in invoice_histories
        $labelMap = [
            'Revisión' => 'Registro',
            'Área Aprobada' => 'Aprobación',
            'Causada' => 'Contabilidad',
            'Tesorería' => 'Tesorería',
            'Pagada' => 'Pagada',
        ];

        foreach ($labelMap as $old => $new) {
            $this->execute(
                "UPDATE invoice_histories SET old_value = '{$new}' WHERE field_changed = 'pipeline_status' AND old_value = '{$old}'"
            );
            $this->execute(
                "UPDATE invoice_histories SET new_value = '{$new}' WHERE field_changed = 'pipeline_status' AND new_value = '{$old}'"
            );
        }
    }

    public function down(): void
    {
        $statusMap = [
            'registro' => 'revision',
            'aprobacion' => 'area_approved',
            'contabilidad' => 'accrued',
            'tesoreria' => 'treasury',
            'pagada' => 'paid',
        ];

        foreach ($statusMap as $old => $new) {
            $this->execute(
                "UPDATE invoices SET pipeline_status = '{$new}' WHERE pipeline_status = '{$old}'"
            );
        }

        foreach ($statusMap as $old => $new) {
            $this->execute(
                "UPDATE invoice_histories SET old_value = '{$new}' WHERE field_changed = 'pipeline_status' AND old_value = '{$old}'"
            );
            $this->execute(
                "UPDATE invoice_histories SET new_value = '{$new}' WHERE field_changed = 'pipeline_status' AND new_value = '{$old}'"
            );
        }

        $labelMap = [
            'Registro' => 'Revisión',
            'Aprobación' => 'Área Aprobada',
            'Contabilidad' => 'Causada',
            'Tesorería' => 'Tesorería',
            'Pagada' => 'Pagada',
        ];

        foreach ($labelMap as $old => $new) {
            $this->execute(
                "UPDATE invoice_histories SET old_value = '{$new}' WHERE field_changed = 'pipeline_status' AND old_value = '{$old}'"
            );
            $this->execute(
                "UPDATE invoice_histories SET new_value = '{$new}' WHERE field_changed = 'pipeline_status' AND new_value = '{$old}'"
            );
        }
    }
}

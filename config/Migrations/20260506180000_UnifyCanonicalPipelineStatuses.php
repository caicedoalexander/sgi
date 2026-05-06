<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Unifica el lenguaje canónico del pipeline en los 4 módulos que usaban slugs
 * divergentes (`aut_pago`, `pagado`) frente a los canónicos de Facturas y
 * Anticipos (`autorizacion_pago`, `pagada`).
 *
 * - `aut_pago`  → `autorizacion_pago`  (Novedades, Caja Menor, Reintegros, Programación de Pagos)
 * - `pagado`    → `pagada`             (Caja Menor, Reintegros)
 *
 * Tablas afectadas: ver mapas $slugUpdates (datos vivos) y $labelUpdates
 * (etiquetas almacenadas en *_histories.old_value / new_value).
 */
class UnifyCanonicalPipelineStatuses extends BaseMigration
{
    public function up(): void
    {
        // --- 1. Datos vivos (slugs) ----------------------------------------------------------
        $slugUpdates = [
            // tabla => [columna, [old => new, ...]]
            'employee_novelties' => ['pipeline_status', ['aut_pago' => 'autorizacion_pago']],
            'novelty_liquidation_docs' => ['pipeline_status', ['aut_pago' => 'autorizacion_pago']],
            'novelty_documents' => ['pipeline_status', ['aut_pago' => 'autorizacion_pago']],
            'payment_schedulings' => ['pipeline_status', ['aut_pago' => 'autorizacion_pago']],
            'petty_cash_records' => ['status', ['aut_pago' => 'autorizacion_pago', 'pagado' => 'pagada']],
            'refunds' => ['status', ['aut_pago' => 'autorizacion_pago', 'pagado' => 'pagada']],
        ];

        foreach ($slugUpdates as $table => [$column, $map]) {
            if (!$this->hasTable($table)) {
                continue;
            }
            foreach ($map as $old => $new) {
                $this->execute(
                    "UPDATE `{$table}` SET `{$column}` = '{$new}' WHERE `{$column}` = '{$old}'",
                );
            }
        }

        // --- 2. Catálogo de permisos de pipeline ---------------------------------------------
        if ($this->hasTable('pipeline_permissions')) {
            $this->execute(
                "UPDATE `pipeline_permissions` SET `step` = 'autorizacion_pago' "
                . "WHERE `step` = 'aut_pago' "
                . "AND `pipeline` IN ('novelties', 'petty_cash', 'refunds', 'payment_schedulings')",
            );
        }

        // --- 3. Etiquetas en *_histories (old_value / new_value) -----------------------------
        // Solo aplica a Caja Menor y Reintegros, que almacenaban 'Pagado' (masculino).
        // 'Aut. Pago' permanece igual: la unificación de slug no cambia la etiqueta corta.
        $labelUpdates = [
            'petty_cash_histories' => ['field_changed' => 'status', 'pk' => 'petty_cash_record_id'],
            'refund_histories' => ['field_changed' => 'status', 'pk' => 'refund_id'],
        ];

        foreach ($labelUpdates as $table => $meta) {
            if (!$this->hasTable($table)) {
                continue;
            }
            $this->execute(
                "UPDATE `{$table}` SET `old_value` = 'Pagada' "
                . "WHERE `field_changed` = '{$meta['field_changed']}' AND `old_value` = 'Pagado'",
            );
            $this->execute(
                "UPDATE `{$table}` SET `new_value` = 'Pagada' "
                . "WHERE `field_changed` = '{$meta['field_changed']}' AND `new_value` = 'Pagado'",
            );
        }
    }

    public function down(): void
    {
        // Reversión simétrica. Atención: revertir labels solo en petty_cash/refund histories.
        $slugReverts = [
            'employee_novelties' => ['pipeline_status', ['autorizacion_pago' => 'aut_pago']],
            'novelty_liquidation_docs' => ['pipeline_status', ['autorizacion_pago' => 'aut_pago']],
            'novelty_documents' => ['pipeline_status', ['autorizacion_pago' => 'aut_pago']],
            'payment_schedulings' => ['pipeline_status', ['autorizacion_pago' => 'aut_pago']],
            'petty_cash_records' => ['status', ['autorizacion_pago' => 'aut_pago', 'pagada' => 'pagado']],
            'refunds' => ['status', ['autorizacion_pago' => 'aut_pago', 'pagada' => 'pagado']],
        ];

        foreach ($slugReverts as $table => [$column, $map]) {
            if (!$this->hasTable($table)) {
                continue;
            }
            foreach ($map as $old => $new) {
                $this->execute(
                    "UPDATE `{$table}` SET `{$column}` = '{$new}' WHERE `{$column}` = '{$old}'",
                );
            }
        }

        if ($this->hasTable('pipeline_permissions')) {
            $this->execute(
                "UPDATE `pipeline_permissions` SET `step` = 'aut_pago' "
                . "WHERE `step` = 'autorizacion_pago' "
                . "AND `pipeline` IN ('novelties', 'petty_cash', 'refunds', 'payment_schedulings')",
            );
        }

        $labelReverts = ['petty_cash_histories' => 'status', 'refund_histories' => 'status'];
        foreach ($labelReverts as $table => $field) {
            if (!$this->hasTable($table)) {
                continue;
            }
            $this->execute(
                "UPDATE `{$table}` SET `old_value` = 'Pagado' "
                . "WHERE `field_changed` = '{$field}' AND `old_value` = 'Pagada'",
            );
            $this->execute(
                "UPDATE `{$table}` SET `new_value` = 'Pagado' "
                . "WHERE `field_changed` = '{$field}' AND `new_value` = 'Pagada'",
            );
        }
    }
}

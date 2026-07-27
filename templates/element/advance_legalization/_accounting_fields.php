<?php
/**
 * Campos de causación del paso Contabilidad de la legalización. Compartido por
 * las 3 salidas del paso (caso exacto, faltante, sobrante), por eso vive en un
 * elemento y no inline: los 3 formularios deben enviar exactamente lo mismo.
 *
 * El select tiene 4 opciones (< 7) → `form-select` plano, sin `select2-enable`.
 * Las opciones vienen del ViewModel (fuente única: InvoiceConstants), nunca
 * escritas a mano acá.
 *
 * @var \App\View\AppView $this
 * @var array<string, string> $readyForPaymentOptions
 */
?>
<div class="row g-2 g-md-3 mb-3">
    <div class="col-md-4">
        <label class="input-label">Causada</label>
        <div class="form-check">
            <input type="checkbox" name="accrued" value="1" id="leg-accrued" class="form-check-input">
            <label for="leg-accrued" class="form-check-label">Marcar como causada</label>
        </div>
    </div>
    <div class="col-md-4">
        <label class="input-label">Fecha de Causación</label>
        <input type="text" name="accrual_date" class="form-control flatpickr-date" value="" required>
    </div>
    <div class="col-md-4">
        <label class="input-label">Lista para Pago</label>
        <select name="ready_for_payment" class="form-select" required>
            <?php foreach ($readyForPaymentOptions as $value => $label): ?>
            <option value="<?= h($value) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

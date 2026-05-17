<?php
/**
 * Sección "Contabilidad": flag accrued, accrual_date, ready_for_payment.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var callable $canEdit
 * @var array<string,string> $readyForPaymentOptions
 */
?>
<div class="mb-4 ">
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="text-uppercase fw-semibold flex-shrink-0"
              style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">
            <i class="bi bi-calculator me-1" aria-hidden="true"></i>Contabilidad
        </span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label d-block">Causada</label>
            <div class="form-check">
                <?= $this->Form->checkbox('accrued', array_merge(
                    ['class' => 'form-check-input'],
                    $canEdit('accrued') ? [] : ['disabled' => true]
                )) ?>
                <?= $this->Form->label('accrued', 'Marcar como causada', ['class' => 'form-check-label']) ?>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label">Fecha de Causación</label>
            <?php if ($canEdit('accrual_date')): ?>
                <input type="text" name="accrual_date" class="form-control flatpickr-date"
                       value="<?= h($viewModel->invoice->accrual_date?->format('Y-m-d') ?? '') ?>">
            <?php else: ?>
                <input type="text" class="form-control" disabled
                       value="<?= h($viewModel->invoice->accrual_date?->format('d/m/Y') ?? '') ?>">
                <input type="hidden" name="accrual_date"
                       value="<?= h($viewModel->invoice->accrual_date?->format('Y-m-d') ?? '') ?>">
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <label class="form-label">Lista para Pago</label>
            <?= $this->Form->control('ready_for_payment', array_merge(
                ['label' => false, 'options' => $readyForPaymentOptions],
                $canEdit('ready_for_payment')
                    ? ['class' => 'form-select']
                    : ['class' => 'form-select', 'disabled' => true]
            )) ?>
        </div>
    </div>
</div>

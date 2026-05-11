<?php
/**
 * Sección "Fechas": registro (read-only), emisión, vencimiento.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var callable $canEdit
 * @var bool $isAdvance
 */

use App\Constants\InvoiceConstants;
?>
<div class="mb-4 ">
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="text-uppercase fw-semibold flex-shrink-0"
              style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
            <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Fechas
        </span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Fecha de Registro</label>
            <input type="text" class="form-control" disabled
                   value="<?= h($viewModel->invoice->registration_date?->format('d/m/Y') ?? '') ?>">
            <input type="hidden" name="registration_date"
                   value="<?= h($viewModel->invoice->registration_date?->format('Y-m-d') ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Fecha de Emisión</label>
            <?php if ($canEdit('issue_date')): ?>
                <input type="text" name="issue_date" class="form-control flatpickr-date"
                       value="<?= h($viewModel->invoice->issue_date?->format('Y-m-d') ?? '') ?>">
            <?php else: ?>
                <input type="text" class="form-control" disabled
                       value="<?= h($viewModel->invoice->issue_date?->format('d/m/Y') ?? '') ?>">
                <input type="hidden" name="issue_date"
                       value="<?= h($viewModel->invoice->issue_date?->format('Y-m-d') ?? '') ?>">
            <?php endif; ?>
        </div>
        <?php if (!$isAdvance): ?>
        <div class="col-md-4" id="due-date-wrapper">
            <label class="form-label">Fecha de Vencimiento</label>
            <?php if ($canEdit('due_date')): ?>
                <input type="text" name="due_date" class="form-control flatpickr-date"
                       value="<?= h($viewModel->invoice->due_date?->format('Y-m-d') ?? '') ?>"
                       <?= ($viewModel->invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA ? 'disabled' : '' ?>>
            <?php else: ?>
                <input type="text" class="form-control" disabled
                       value="<?= h($viewModel->invoice->due_date?->format('d/m/Y') ?? '') ?>">
                <input type="hidden" name="due_date"
                       value="<?= h($viewModel->invoice->due_date?->format('Y-m-d') ?? '') ?>">
            <?php endif; ?>
        </div>
        <?php else: ?>
        <input type="hidden" name="due_date" value="<?= h($viewModel->invoice->due_date?->format('Y-m-d') ?? '') ?>">
        <?php endif; ?>
    </div>
</div>

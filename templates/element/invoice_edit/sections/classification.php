<?php
/**
 * Sección "Clasificación y Valor": operation_center, expense_type,
 * cost_center, amount (currency) y detail.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var callable $canEdit
 * @var bool $isAdvance
 */
?>
<div class="mb-4 ">
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="text-uppercase fw-semibold flex-shrink-0"
              style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
            <i class="bi bi-tags me-1" aria-hidden="true"></i>Clasificación y Valor
        </span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Centro de Operación</label>
            <?= $this->Form->control('operation_center_id', array_merge(
                ['label' => false, 'options' => $viewModel->operationCenters, 'empty' => '-- Seleccione --'],
                ($canEdit('operation_center_id') && !$isAdvance)
                    ? ['class' => 'form-select']
                    : ['class' => 'form-select', 'disabled' => true]
            )) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label">Tipo de Gasto</label>
            <?= $this->Form->control('expense_type_id', array_merge(
                ['label' => false, 'options' => $viewModel->expenseTypes, 'empty' => '-- Seleccione --'],
                $canEdit('expense_type_id')
                    ? ['class' => 'form-select']
                    : ['class' => 'form-select', 'disabled' => true]
            )) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label">Centro de Costos</label>
            <?= $this->Form->control('cost_center_id', array_merge(
                ['label' => false, 'options' => $viewModel->costCenters, 'empty' => '-- Seleccione --'],
                $canEdit('cost_center_id')
                    ? ['class' => 'form-select']
                    : ['class' => 'form-select', 'disabled' => true]
            )) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label">Valor (COP)</label>
            <?php if ($canEdit('amount')): ?>
                <input type="text" name="amount" class="form-control currency-input"
                       value="<?= h($viewModel->invoice->amount ?? '') ?>">
            <?php else: ?>
                <input type="text" class="form-control" disabled
                       value="$ <?= number_format((float)($viewModel->invoice->amount ?? 0), 0, ',', '.') ?>">
            <?php endif; ?>
        </div>
    </div>
    <div class="mt-3">
        <label class="form-label">Detalle</label>
        <?= $this->Form->control('detail', array_merge(
            ['label' => false, 'type' => 'textarea', 'rows' => 1],
            $canEdit('detail')
                ? ['class' => 'form-control auto-resize']
                : ['class' => 'form-control auto-resize', 'disabled' => true]
        )) ?>
    </div>
</div>

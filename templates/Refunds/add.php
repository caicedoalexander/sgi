<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\RefundAddViewModel $viewModel
 */
$record = $viewModel->record;
$employees = $viewModel->employees;
$providers = $viewModel->providers;
$operationCenters = $viewModel->operationCenters;
$this->assign('title', 'Nuevo Reintegro');
?>
<?= $this->element('cdn_select2') ?>

<div class="spi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="spi-breadcrumb">
            <?= $this->Html->link('Reintegros', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Nuevo Reintegro</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="spi-page-title">Nuevo Reintegro</span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
    </div>
</div>

<div class="spi-card">
    <div class="d-flex align-items-center gap-3" style="margin-bottom:20px;">
        <div class="spi-icon-chip">
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
        </div>
        <div>
            <div class="spi-card-title">Crear Reintegro</div>
            <div style="font-size:.72rem;color:var(--text-disabled);">Seleccione el beneficiario del reintegro</div>
        </div>
    </div>

    <?= $this->Form->create($record) ?>

    <div class="mb-4">
        <label class="input-label" for="refund-operation-center">Centro de Operación <span class="text-danger">*</span></label>
        <select name="operation_center_id" id="refund-operation-center" class="form-select select2-enable" required>
            <option value="">Selecciona un centro...</option>
            <?php foreach ($operationCenters as $id => $name): ?>
                <option value="<?= (int)$id ?>"><?= h($name) ?></option>
            <?php endforeach; ?>
        </select>
        <small class="input-help">El código se generará automáticamente como <code>REI-{año}-{centro}-{consecutivo}</code>.</small>
    </div>

    <div class="mb-4">
        <label class="input-label">Tipo de beneficiario <span class="text-danger">*</span></label>
        <div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="beneficiary_type" id="bt-employee" value="employee">
                <label class="form-check-label" for="bt-employee">Empleado</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="beneficiary_type" id="bt-provider" value="provider">
                <label class="form-check-label" for="bt-provider">Proveedor</label>
            </div>
        </div>
    </div>

    <div class="mb-4 spi-beneficiary-employee" style="display:none;">
        <label class="input-label">Empleado</label>
        <select name="beneficiary_employee_id" class="form-select select2-enable">
            <option value="">Seleccione un empleado</option>
            <?php foreach ($employees as $id => $name): ?>
            <option value="<?= (int)$id ?>"><?= h($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-4 spi-beneficiary-provider" style="display:none;">
        <label class="input-label">Proveedor</label>
        <select name="beneficiary_provider_id" class="form-select select2-enable">
            <option value="">Seleccione un proveedor</option>
            <?php foreach ($providers as $id => $name): ?>
            <option value="<?= (int)$id ?>"><?= h($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="d-flex gap-2 pt-3" style="border-top:1px solid var(--rule);">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Crear Reintegro
        </button>
        <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-default']) ?>
    </div>

    <?= $this->Form->end() ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var radios = document.querySelectorAll('input[name="beneficiary_type"]');
    var empBlock = document.querySelector('.spi-beneficiary-employee');
    var provBlock = document.querySelector('.spi-beneficiary-provider');
    var empSelect = empBlock.querySelector('select');
    var provSelect = provBlock.querySelector('select');

    function sync() {
        var checked = document.querySelector('input[name="beneficiary_type"]:checked');
        var val = checked ? checked.value : null;
        empBlock.style.display = val === 'employee' ? '' : 'none';
        provBlock.style.display = val === 'provider' ? '' : 'none';
        if (val !== 'employee') { empSelect.value = ''; }
        if (val !== 'provider') { provSelect.value = ''; }
    }
    radios.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
});
</script>

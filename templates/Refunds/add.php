<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Refund $record
 * @var array $employees
 * @var array $providers
 * @var iterable $operationCenters
 */
$this->assign('title', 'Nuevo Reintegro');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nuevo Reintegro</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-header d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center flex-shrink-0"
             style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
            <i class="bi bi-arrow-counterclockwise"></i>
        </div>
        <div>
            <div style="font-size:.95rem;font-weight:700;color:#111;">Crear Reintegro</div>
            <div style="font-size:.72rem;color:#aaa;">Seleccione el beneficiario del reintegro</div>
        </div>
    </div>
    <div class="card-body p-4">
        <?= $this->Form->create($record) ?>

        <div class="mb-4">
            <label class="form-label" for="refund-operation-center">Centro de Operación <span class="text-danger">*</span></label>
            <select name="operation_center_id" id="refund-operation-center" class="form-select select2-enable" required>
                <option value="">Selecciona un centro...</option>
                <?php foreach ($operationCenters as $id => $name): ?>
                    <option value="<?= (int)$id ?>"><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">El código se generará automáticamente como <code>REI-{año}-{centro}-{consecutivo}</code>.</small>
        </div>

        <div class="mb-4">
            <label class="form-label">Tipo de beneficiario <span class="text-danger">*</span></label>
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

        <div class="mb-4 sgi-beneficiary-employee" style="display:none;">
            <label class="form-label">Empleado</label>
            <select name="beneficiary_employee_id" class="form-select select2-enable">
                <option value="">Seleccione un empleado</option>
                <?php foreach ($employees as $id => $name): ?>
                <option value="<?= (int)$id ?>"><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-4 sgi-beneficiary-provider" style="display:none;">
            <label class="form-label">Proveedor</label>
            <select name="beneficiary_provider_id" class="form-select select2-enable">
                <option value="">Seleccione un proveedor</option>
                <?php foreach ($providers as $id => $name): ?>
                <option value="<?= (int)$id ?>"><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="d-flex gap-2 pt-2" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="sgi-btn-primary btn">
                <i class="bi bi-plus-lg me-1"></i>Crear Reintegro
            </button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var radios = document.querySelectorAll('input[name="beneficiary_type"]');
    var empBlock = document.querySelector('.sgi-beneficiary-employee');
    var provBlock = document.querySelector('.sgi-beneficiary-provider');
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

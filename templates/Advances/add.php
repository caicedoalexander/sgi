<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var \Cake\Collection\CollectionInterface $providers
 * @var \Cake\Collection\CollectionInterface $employees
 * @var \Cake\Collection\CollectionInterface $operationCenters
 * @var \Cake\Collection\CollectionInterface $expenseTypes
 * @var \Cake\Collection\CollectionInterface $costCenters
 */
$this->assign('title', 'Nuevo Anticipo');
?>
<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<!-- Encabezado de página -->
<div class="spi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="spi-breadcrumb">
            <?= $this->Html->link('Anticipos', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Nuevo</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="spi-page-title">Nuevo Anticipo</span>
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
        <div class="spi-icon-chip" style="font-size:.95rem;">
            <i class="bi bi-cash-coin" aria-hidden="true"></i>
        </div>
        <span style="font-size:var(--fs-title-card);font-weight:600;color:var(--text-default);">Información del Anticipo</span>
    </div>

    <?= $this->Form->create($invoice) ?>

    <!-- Sección: Beneficiario -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="text-uppercase fw-semibold flex-shrink-0"
                  style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">Beneficiario</span>
            <div style="flex:1;height:1px;background:var(--rule);"></div>
        </div>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="input-label">Tipo de Beneficiario</label>
                <select id="beneficiary-type" class="form-select" required>
                    <option value="">-- Seleccione --</option>
                    <option value="provider">Proveedor</option>
                    <option value="employee">Empleado</option>
                </select>
            </div>
            <div class="col-md-9 d-none" id="provider-wrapper">
                <?= $this->Form->control('provider_id', [
                    'class'   => 'form-select select2-enable',
                    'label'   => ['text' => 'Proveedor', 'class' => 'input-label'],
                    'options' => $providers,
                    'empty'   => '-- Seleccione --',
                    'disabled' => true,
                ]) ?>
            </div>
            <div class="col-md-9 d-none" id="employee-wrapper">
                <?= $this->Form->control('employee_id', [
                    'class'   => 'form-select select2-enable',
                    'label'   => ['text' => 'Empleado', 'class' => 'input-label'],
                    'options' => $employees,
                    'empty'   => '-- Seleccione --',
                    'disabled' => true,
                ]) ?>
            </div>
        </div>
    </div>

    <!-- Sección: Fechas -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="text-uppercase fw-semibold flex-shrink-0"
                  style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">Fechas</span>
            <div style="flex:1;height:1px;background:var(--rule);"></div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <?= $this->Form->control('issue_date', [
                    'type'  => 'text',
                    'class' => 'form-control flatpickr-date',
                    'label' => ['text' => 'Fecha de Emisión', 'class' => 'input-label'],
                ]) ?>
            </div>
        </div>
    </div>

    <!-- Sección: Clasificación y Valor -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="text-uppercase fw-semibold flex-shrink-0"
                  style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">Clasificación y Valor</span>
            <div style="flex:1;height:1px;background:var(--rule);"></div>
        </div>
        <div class="row g-3">
            <div class="col-md-3">
                <?= $this->Form->control('operation_center_id', [
                    'class'   => 'form-select select2-enable',
                    'label'   => ['text' => 'Centro de Operación', 'class' => 'input-label'],
                    'options' => $operationCenters,
                    'empty'   => '-- Seleccione --',
                ]) ?>
            </div>
            <div class="col-md-3">
                <?= $this->Form->control('expense_type_id', [
                    'class'   => 'form-select select2-enable',
                    'label'   => ['text' => 'Tipo de Gasto', 'class' => 'input-label'],
                    'options' => $expenseTypes,
                    'empty'   => '-- Seleccione --',
                ]) ?>
            </div>
            <div class="col-md-3">
                <?= $this->Form->control('cost_center_id', [
                    'class'   => 'form-select select2-enable',
                    'label'   => ['text' => 'Centro de Costos', 'class' => 'input-label'],
                    'options' => $costCenters,
                    'empty'   => '-- Seleccione --',
                ]) ?>
            </div>
            <div class="col-md-3">
                <label class="input-label">Valor (COP)</label>
                <input type="text" name="amount" class="form-control currency-input"
                       value="<?= h($invoice->amount ?? '') ?>" required>
            </div>
        </div>
    </div>

    <!-- Sección: Descripción -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="text-uppercase fw-semibold flex-shrink-0"
                  style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">Descripción</span>
            <div style="flex:1;height:1px;background:var(--rule);"></div>
        </div>
        <div>
            <?= $this->Form->control('detail', [
                'type'  => 'textarea',
                'rows'  => 3,
                'class' => 'form-control',
                'label' => ['text' => 'Concepto / Detalle', 'class' => 'input-label'],
            ]) ?>
        </div>
    </div>

    <div class="d-flex gap-2 pt-3" style="border-top:1px solid var(--rule);">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar Anticipo
        </button>
        <?= $this->Html->link(
            'Cancelar',
            ['action' => 'index'],
            ['class' => 'btn btn-default']
        ) ?>
    </div>

    <?= $this->Form->end() ?>
</div>

<?php $this->append('script'); ?>
<script>
(function () {
    const typeSelect = document.getElementById('beneficiary-type');
    const providerWrapper = document.getElementById('provider-wrapper');
    const employeeWrapper = document.getElementById('employee-wrapper');
    const providerSelect = providerWrapper.querySelector('select');
    const employeeSelect = employeeWrapper.querySelector('select');

    function applyToggle() {
        const value = typeSelect.value;
        if (value === 'provider') {
            providerWrapper.classList.remove('d-none');
            employeeWrapper.classList.add('d-none');
            providerSelect.disabled = false;
            providerSelect.required = true;
            employeeSelect.disabled = true;
            employeeSelect.required = false;
            employeeSelect.value = '';
            if (employeeSelect.dispatchEvent) {
                employeeSelect.dispatchEvent(new Event('change'));
            }
        } else if (value === 'employee') {
            employeeWrapper.classList.remove('d-none');
            providerWrapper.classList.add('d-none');
            employeeSelect.disabled = false;
            employeeSelect.required = true;
            providerSelect.disabled = true;
            providerSelect.required = false;
            providerSelect.value = '';
            if (providerSelect.dispatchEvent) {
                providerSelect.dispatchEvent(new Event('change'));
            }
        } else {
            providerWrapper.classList.add('d-none');
            employeeWrapper.classList.add('d-none');
            providerSelect.disabled = true;
            employeeSelect.disabled = true;
            providerSelect.required = false;
            employeeSelect.required = false;
        }

        // select2 no observa la asignación directa `.disabled = ...`; sí responde
        // a jQuery `.prop('disabled', ...)`. Sin esto, el widget queda deshabilitado
        // tras mostrar el beneficiario (empleado/proveedor).
        if (window.jQuery && window.jQuery.fn.select2) {
            window.jQuery(providerSelect).prop('disabled', providerSelect.disabled);
            window.jQuery(employeeSelect).prop('disabled', employeeSelect.disabled);
        }
    }

    typeSelect.addEventListener('change', applyToggle);
    applyToggle();
})();
</script>
<?php $this->end(); ?>

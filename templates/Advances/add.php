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

<!-- Encabezado de página -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nuevo Anticipo</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <!-- Cabecera de la tarjeta -->
    <div class="card-header d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center flex-shrink-0"
             style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.95rem;">
            <i class="bi bi-cash-coin" aria-hidden="true"></i>
        </div>
        <span style="font-size:.875rem;font-weight:600;color:#333;">Información del Anticipo</span>
    </div>

    <div class="card-body p-4">
        <?= $this->Form->create($invoice) ?>

        <!-- Sección: Beneficiario -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">Beneficiario</span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Tipo de Beneficiario</label>
                    <select id="beneficiary-type" class="form-select" required>
                        <option value="">-- Seleccione --</option>
                        <option value="provider">Proveedor</option>
                        <option value="employee">Empleado</option>
                    </select>
                </div>
                <div class="col-md-9 d-none" id="provider-wrapper">
                    <?= $this->Form->control('provider_id', [
                        'class'   => 'form-select select2-enable',
                        'label'   => ['text' => 'Proveedor', 'class' => 'form-label'],
                        'options' => $providers,
                        'empty'   => '-- Seleccione --',
                        'disabled' => true,
                    ]) ?>
                </div>
                <div class="col-md-9 d-none" id="employee-wrapper">
                    <?= $this->Form->control('employee_id', [
                        'class'   => 'form-select select2-enable',
                        'label'   => ['text' => 'Empleado', 'class' => 'form-label'],
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
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">Fechas</span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <?= $this->Form->control('issue_date', [
                        'type'  => 'text',
                        'class' => 'form-control flatpickr-date',
                        'label' => ['text' => 'Fecha de Emisión', 'class' => 'form-label'],
                    ]) ?>
                </div>
            </div>
        </div>

        <!-- Sección: Clasificación y Valor -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">Clasificación y Valor</span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <?= $this->Form->control('operation_center_id', [
                        'class'   => 'form-select select2-enable',
                        'label'   => ['text' => 'Centro de Operación', 'class' => 'form-label'],
                        'options' => $operationCenters,
                        'empty'   => '-- Seleccione --',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('expense_type_id', [
                        'class'   => 'form-select select2-enable',
                        'label'   => ['text' => 'Tipo de Gasto', 'class' => 'form-label'],
                        'options' => $expenseTypes,
                        'empty'   => '-- Seleccione --',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('cost_center_id', [
                        'class'   => 'form-select select2-enable',
                        'label'   => ['text' => 'Centro de Costos', 'class' => 'form-label'],
                        'options' => $costCenters,
                        'empty'   => '-- Seleccione --',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Valor (COP)</label>
                    <input type="text" name="amount" class="form-control currency-input"
                           value="<?= h($invoice->amount ?? '') ?>" required>
                </div>
            </div>
        </div>

        <!-- Sección: Descripción -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">Descripción</span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div>
                <?= $this->Form->control('detail', [
                    'type'  => 'textarea',
                    'rows'  => 3,
                    'class' => 'form-control',
                    'label' => ['text' => 'Concepto / Detalle', 'class' => 'form-label'],
                ]) ?>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar Anticipo
            </button>
            <?= $this->Html->link(
                'Cancelar',
                ['action' => 'index'],
                ['class' => 'btn btn-outline-secondary']
            ) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>

<?php $this->start('script'); ?>
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
    }

    typeSelect.addEventListener('change', applyToggle);
    applyToggle();
})();
</script>
<?php $this->end(); ?>

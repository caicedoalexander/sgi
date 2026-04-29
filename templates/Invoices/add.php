<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 */
$this->assign('title', 'Nueva Factura');

$documentTypes = [
    'Factura'             => 'Factura',
    'Nota Debito'         => 'Nota Débito',
    'Caja menor'          => 'Caja menor',
    'Tarjeta de Crédito'  => 'Tarjeta de Crédito',
    'Reintegro'           => 'Reintegro',
    'Legalización'        => 'Legalización',
    'Recibo'              => 'Recibo',
    'Anticipo'            => 'Anticipo',
];
?>

<!-- Encabezado de página -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nueva Factura</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <!-- Cabecera de la tarjeta -->
    <div class="card-header d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center flex-shrink-0"
             style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.95rem;">
            <i class="bi bi-receipt"></i>
        </div>
        <span style="font-size:.875rem;font-weight:600;color:#333;">Información de la Factura</span>
    </div>

    <div class="card-body p-4">
        <?= $this->Form->create($invoice) ?>

        <!-- Sección: Documento -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">Documento</span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <?= $this->Form->control('invoice_number', [
                        'class'       => 'form-control',
                        'label'       => ['text' => 'No. Factura', 'class' => 'form-label'],
                        'placeholder' => 'Ej: FV-001234',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('document_type', [
                        'class'   => 'form-select',
                        'label'   => ['text' => 'Tipo de Documento', 'class' => 'form-label'],
                        'options' => $documentTypes,
                        'empty'   => '-- Seleccione --',
                    ]) ?>
                </div>
                <div class="col-md-3" id="purchase-order-wrapper">
                    <?= $this->Form->control('purchase_order', [
                        'class' => 'form-control',
                        'label' => ['text' => 'Orden de Compra', 'class' => 'form-label'],
                    ]) ?>
                </div>
                <div class="col-md-3" id="provider-wrapper">
                    <?= $this->Form->control('provider_id', [
                        'class'   => 'form-select select2-enable',
                        'label'   => ['text' => 'Proveedor', 'class' => 'form-label'],
                        'options' => $providers,
                        'empty'   => '-- Seleccione --',
                    ]) ?>
                </div>
            </div>

            <!-- Documento Equivalente -->
            <div class="row g-3 mt-1" id="equivalent-doc-row">
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <?= $this->Form->checkbox('is_equivalent_document', [
                            'class' => 'form-check-input',
                            'id' => 'is-equivalent-document',
                        ]) ?>
                        <label class="form-check-label" for="is-equivalent-document">
                            Es Documento Equivalente
                        </label>
                    </div>
                </div>
                <div class="col-md-3 d-none" id="holder-type-wrapper">
                    <?= $this->Form->control('equivalent_holder_type', [
                        'class'   => 'form-select',
                        'label'   => ['text' => 'Titular del Documento', 'class' => 'form-label'],
                        'options' => ['provider' => 'Proveedor', 'employee' => 'Empleado', 'manual' => 'Cédula Manual'],
                        'empty'   => '-- Seleccione --',
                        'id'      => 'equivalent-holder-type',
                    ]) ?>
                </div>
                <div class="col-md-3 d-none" id="employee-wrapper">
                    <?= $this->Form->control('employee_id', [
                        'class'   => 'form-select select2-enable',
                        'label'   => ['text' => 'Empleado', 'class' => 'form-label'],
                        'options' => $employees ?? [],
                        'empty'   => '-- Seleccione --',
                    ]) ?>
                </div>
                <div class="col-md-3 d-none" id="manual-doc-wrapper">
                    <?= $this->Form->control('manual_document_number', [
                        'class'       => 'form-control',
                        'label'       => ['text' => 'Cédula', 'class' => 'form-label'],
                        'placeholder' => 'Número de cédula',
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
                <div class="col-md-6" id="due-date-wrapper">
                    <?= $this->Form->control('due_date', [
                        'type'  => 'text',
                        'class' => 'form-control flatpickr-date',
                        'label' => ['text' => 'Fecha de Vencimiento', 'class' => 'form-label'],
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
                        'class'   => 'form-select',
                        'label'   => ['text' => 'Centro de Operación', 'class' => 'form-label'],
                        'options' => $operationCenters,
                        'empty'   => '-- Seleccione --',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('expense_type_id', [
                        'class'   => 'form-select',
                        'label'   => ['text' => 'Tipo de Gasto', 'class' => 'form-label'],
                        'options' => $expenseTypes,
                        'empty'   => '-- Seleccione --',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('cost_center_id', [
                        'class'   => 'form-select',
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

        <!-- Sección: Detalle y Observaciones -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">Descripción</span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="mb-3">
                <?= $this->Form->control('detail', [
                    'type'  => 'textarea',
                    'rows'  => 3,
                    'class' => 'form-control',
                    'label' => ['text' => 'Detalle', 'class' => 'form-label'],
                ]) ?>
            </div>
            <div>
                <?= $this->Form->control('observations', [
                    'type'  => 'textarea',
                    'rows'  => 2,
                    'class' => 'form-control',
                    'label' => ['text' => 'Observaciones', 'class' => 'form-label'],
                ]) ?>
            </div>
        </div>

        <?= $this->Form->hidden('pipeline_status', ['value' => 'aprobacion']) ?>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Guardar Factura
            </button>
            <?= $this->Html->link(
                'Cancelar',
                ['action' => 'index'],
                ['class' => 'btn btn-outline-secondary']
            ) ?>
        </div>

        <?= $this->Form->end() ?>

<?php $this->append('script') ?>
<script>
(function () {
    // Tipo de Documento: Legalización oculta campos no aplicables (Orden de Compra,
    // Fecha de Vencimiento, Documento Equivalente). Cualquier otro tipo los muestra.
    var docTypeSelect = document.querySelector('select[name="document_type"]');
    if (!docTypeSelect) return;

    var purchaseOrder = document.getElementById('purchase-order-wrapper');
    var dueDate = document.getElementById('due-date-wrapper');
    var equivalentRow = document.getElementById('equivalent-doc-row');

    function setDisabled(wrapper, disabled) {
        if (!wrapper) return;
        wrapper.classList.toggle('d-none', disabled);
        wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
            el.disabled = disabled;
            if (disabled) { el.value = ''; el.checked = false; }
        });
    }

    function applyDocTypeRules() {
        var value = docTypeSelect.value;
        var isLegalization = value === 'Legalización';
        setDisabled(purchaseOrder, isLegalization);
        setDisabled(dueDate, isLegalization);
        setDisabled(equivalentRow, isLegalization);
    }

    docTypeSelect.addEventListener('change', applyDocTypeRules);
    applyDocTypeRules();
})();
</script>
<?php $this->end() ?>
    </div>
</div>
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 */
$this->assign('title', 'Nueva Factura');

$documentTypes = [
    'Factura' => 'Factura',
    'Nota Debito' => 'Nota Débito',
    'Caja menor' => 'Caja menor',
    'Tarjeta de Crédito' => 'Tarjeta de Crédito',
    'Reintegro' => 'Reintegro',
    'Legalización' => 'Legalización',
    'Recibo' => 'Recibo',
    'Anticipo' => 'Anticipo',
];
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Nueva Factura</h5></div>
    <div class="card-body">
        <?= $this->Form->create($invoice) ?>

        <h6 class="text-muted mb-3">Información del Documento</h6>
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('invoice_number', ['class' => 'form-control', 'label' => ['text' => 'No. Factura', 'class' => 'form-label'], 'placeholder' => 'Ej: FV-001234']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('document_type', ['class' => 'form-select', 'label' => ['text' => 'Tipo de Documento', 'class' => 'form-label'], 'options' => $documentTypes, 'empty' => '-- Seleccione --']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('purchase_order', ['class' => 'form-control', 'label' => ['text' => 'Orden de Compra', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('provider_id', ['class' => 'form-select', 'label' => ['text' => 'Proveedor', 'class' => 'form-label'], 'options' => $providers, 'empty' => '-- Seleccione --']) ?>
            </div>
        </div>

        <h6 class="text-muted mb-3">Fechas</h6>
        <div class="row">
            <div class="col-md-4 mb-3">
                <?= $this->Form->control('registration_date', ['type' => 'text', 'class' => 'form-control flatpickr-date', 'label' => ['text' => 'Fecha de Registro', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-4 mb-3">
                <?= $this->Form->control('issue_date', ['type' => 'text', 'class' => 'form-control flatpickr-date', 'label' => ['text' => 'Fecha de Emisión', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-4 mb-3">
                <?= $this->Form->control('due_date', ['type' => 'text', 'class' => 'form-control flatpickr-date', 'label' => ['text' => 'Fecha de Vencimiento', 'class' => 'form-label']]) ?>
            </div>
        </div>

        <h6 class="text-muted mb-3">Clasificación y Valor</h6>
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('operation_center_id', ['class' => 'form-select', 'label' => ['text' => 'Centro de Operación', 'class' => 'form-label'], 'options' => $operationCenters, 'empty' => '-- Seleccione --']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('expense_type_id', ['class' => 'form-select', 'label' => ['text' => 'Tipo de Gasto', 'class' => 'form-label'], 'options' => $expenseTypes, 'empty' => '-- Seleccione --']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('cost_center_id', ['class' => 'form-select', 'label' => ['text' => 'Centro de Costos', 'class' => 'form-label'], 'options' => $costCenters, 'empty' => '-- Seleccione --']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Valor (COP)</label>
                <input type="text" name="amount" class="form-control currency-input"
                       value="<?= h($invoice->amount ?? '') ?>" required>
            </div>
        </div>

        <div class="mb-3">
            <?= $this->Form->control('detail', ['type' => 'textarea', 'rows' => 3, 'class' => 'form-control', 'label' => ['text' => 'Detalle', 'class' => 'form-label']]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('observations', ['type' => 'textarea', 'rows' => 2, 'class' => 'form-control', 'label' => ['text' => 'Observaciones', 'class' => 'form-label']]) ?>
        </div>

        <?= $this->Form->hidden('pipeline_status', ['value' => 'revision']) ?>

        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

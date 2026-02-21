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
                <div class="col-md-3">
                    <?= $this->Form->control('purchase_order', [
                        'class' => 'form-control',
                        'label' => ['text' => 'Orden de Compra', 'class' => 'form-label'],
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('provider_id', [
                        'class'   => 'form-select',
                        'label'   => ['text' => 'Proveedor', 'class' => 'form-label'],
                        'options' => $providers,
                        'empty'   => '-- Seleccione --',
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
                <div class="col-md-4">
                    <?= $this->Form->control('registration_date', [
                        'type'  => 'text',
                        'class' => 'form-control flatpickr-date',
                        'label' => ['text' => 'Fecha de Registro', 'class' => 'form-label'],
                    ]) ?>
                </div>
                <div class="col-md-4">
                    <?= $this->Form->control('issue_date', [
                        'type'  => 'text',
                        'class' => 'form-control flatpickr-date',
                        'label' => ['text' => 'Fecha de Emisión', 'class' => 'form-label'],
                    ]) ?>
                </div>
                <div class="col-md-4">
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

        <?= $this->Form->hidden('pipeline_status', ['value' => 'registro']) ?>

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
    </div>
</div>
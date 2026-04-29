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
<div class="container py-4">
    <h2 class="h3 mb-3">Nuevo Anticipo</h2>
    <?= $this->Form->create($invoice) ?>
    <div class="card">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Proveedor (beneficiario)</label>
                    <?= $this->Form->select('provider_id', $providers, ['class' => 'form-select select2', 'empty' => '— Seleccione —']) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Empleado (beneficiario)</label>
                    <?= $this->Form->select('employee_id', $employees, ['class' => 'form-select select2', 'empty' => '— Seleccione —']) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Centro de Operación *</label>
                    <?= $this->Form->select('operation_center_id', $operationCenters, ['class' => 'form-select select2', 'required' => true, 'empty' => '— Seleccione —']) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipo de Gasto *</label>
                    <?= $this->Form->select('expense_type_id', $expenseTypes, ['class' => 'form-select select2', 'required' => true, 'empty' => '— Seleccione —']) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Centro de Costos</label>
                    <?= $this->Form->select('cost_center_id', $costCenters, ['class' => 'form-select select2', 'empty' => '— Sin asignar —']) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha de Emisión *</label>
                    <?= $this->Form->control('issue_date', ['label' => false, 'class' => 'form-control flatpickr-date', 'required' => true, 'type' => 'text']) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Monto *</label>
                    <?= $this->Form->control('amount', ['label' => false, 'class' => 'form-control currency-input', 'required' => true, 'type' => 'text']) ?>
                </div>
                <div class="col-12">
                    <label class="form-label">Concepto / Detalle *</label>
                    <?= $this->Form->control('detail', ['label' => false, 'class' => 'form-control', 'required' => true, 'type' => 'textarea', 'rows' => 3]) ?>
                </div>
            </div>
        </div>
        <div class="card-footer text-end">
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-link']) ?>
            <button type="submit" class="btn sgi-btn-primary">Guardar Anticipo</button>
        </div>
    </div>
    <?= $this->Form->end() ?>
</div>

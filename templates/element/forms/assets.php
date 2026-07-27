<?php
/**
 * Campos del form de Activo (add/edit). Asume estar dentro de Form->create($asset).
 * El estado y el responsable NO se editan aquí: se gestionan vía movimientos.
 *
 * @var \App\View\AppView $this
 * @var array<int, string> $categories
 * @var array<int, string> $operationCenters
 * @var array<int, string> $costCenters
 */
?>
<div class="row g-3">
    <div class="col-md-6">
        <?= $this->Form->control('asset_category_id', [
            'options' => $categories,
            'empty' => 'Seleccione…',
            'class' => 'form-select select2-enable',
            'label' => ['text' => 'Categoría', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('serial_number', [
            'class' => 'form-control',
            'label' => ['text' => 'Número de serie', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('brand', [
            'class' => 'form-control',
            'label' => ['text' => 'Marca', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('model', [
            'class' => 'form-control',
            'label' => ['text' => 'Modelo', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('operation_center_id', [
            'options' => $operationCenters,
            'empty' => 'Seleccione…',
            'class' => 'form-select select2-enable',
            'label' => ['text' => 'Centro de operación', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('cost_center_id', [
            'options' => $costCenters,
            'empty' => 'Sin centro de costo',
            'class' => 'form-select select2-enable',
            'label' => ['text' => 'Centro de costo', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('acquisition_date', [
            'type' => 'text',
            'class' => 'form-control flatpickr-date',
            'label' => ['text' => 'Fecha de adquisición', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-12">
        <?= $this->Form->control('description', [
            'type' => 'textarea', 'rows' => 2,
            'class' => 'form-control',
            'label' => ['text' => 'Descripción', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-12">
        <?= $this->Form->control('observations', [
            'type' => 'textarea', 'rows' => 2,
            'class' => 'form-control',
            'label' => ['text' => 'Observaciones', 'class' => 'input-label'],
        ]) ?>
    </div>
</div>

<?php
/**
 * Campos del form de Consumible (add/edit). El stock actual no se edita aquí:
 * se gestiona con entradas/salidas. Asume estar dentro de Form->create($entity).
 *
 * @var \App\View\AppView $this
 * @var array<int, string> $operationCenters
 */
?>
<div class="row g-3">
    <div class="col-md-4">
        <?= $this->Form->control('reference', ['class' => 'form-control', 'label' => ['text' => 'Referencia', 'class' => 'input-label']]) ?>
    </div>
    <div class="col-md-8">
        <?= $this->Form->control('description', ['class' => 'form-control', 'label' => ['text' => 'Descripción', 'class' => 'input-label']]) ?>
    </div>
    <div class="col-md-3">
        <?= $this->Form->control('minimum_stock', ['type' => 'number', 'min' => 0, 'class' => 'form-control', 'label' => ['text' => 'Stock mínimo', 'class' => 'input-label']]) ?>
    </div>
    <div class="col-md-3">
        <?= $this->Form->control('maximum_stock', ['type' => 'number', 'min' => 0, 'class' => 'form-control', 'label' => ['text' => 'Stock máximo', 'class' => 'input-label']]) ?>
    </div>
    <div class="col-md-3">
        <?= $this->Form->control('unit', ['class' => 'form-control', 'label' => ['text' => 'Unidad', 'class' => 'input-label']]) ?>
    </div>
    <div class="col-md-3">
        <?= $this->Form->control('operation_center_id', [
            'options' => $operationCenters, 'empty' => 'Sin sede', 'class' => 'form-select',
            'label' => ['text' => 'Sede', 'class' => 'input-label'],
        ]) ?>
    </div>
</div>

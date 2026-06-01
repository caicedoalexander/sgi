<?php
/**
 * Campos del form de Organización Temporal. Compartido por la página standalone
 * (add/edit) y el modal AJAX. Asume estar dentro de un Form->create($entity).
 *
 * @var \App\View\AppView $this
 */
?>
<div class="row">
    <div class="col-md-6 mb-3">
        <?= $this->Form->control('name', [
            'class' => 'form-control',
            'label' => ['text' => 'Nombre', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-4 mb-3">
        <?= $this->Form->control('nit', [
            'class' => 'form-control',
            'label' => ['text' => 'NIT', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-2 mb-3 d-flex align-items-end">
        <div class="form-check">
            <?= $this->Form->checkbox('active', ['class' => 'form-check-input', 'checked' => true]) ?>
            <?= $this->Form->label('active', 'Activa', ['class' => 'form-check-label']) ?>
        </div>
    </div>
</div>

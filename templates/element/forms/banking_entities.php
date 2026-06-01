<?php
/**
 * Campos del form de Entidad Bancaria. Compartido por la página standalone
 * (add/edit) y el modal AJAX. Asume estar dentro de un Form->create($entity).
 *
 * @var \App\View\AppView $this
 */
?>
<div class="row g-3">
    <div class="col-md-4">
        <?= $this->Form->control('code', [
            'class' => 'form-control',
            'label' => ['text' => 'Código', 'class' => 'input-label'],
            'placeholder' => 'Ej: 001',
        ]) ?>
    </div>
    <div class="col-md-8">
        <?= $this->Form->control('name', [
            'class' => 'form-control',
            'label' => ['text' => 'Nombre', 'class' => 'input-label'],
            'placeholder' => 'Ej: Bancolombia',
        ]) ?>
    </div>
    <div class="col-md-4">
        <div class="form-check mt-2">
            <?= $this->Form->checkbox('active', ['class' => 'form-check-input', 'id' => 'active-check']) ?>
            <label class="form-check-label" for="active-check">Activo</label>
        </div>
    </div>
</div>

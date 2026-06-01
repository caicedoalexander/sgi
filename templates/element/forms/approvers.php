<?php
/**
 * Campos del form de Aprobador. Compartido por la página standalone
 * (add/edit) y el modal AJAX. Asume estar dentro de un Form->create($entity).
 *
 * @var \App\View\AppView $this
 * @var iterable $users
 * @var iterable $operationCenters
 */
?>
<div class="mb-3">
    <?= $this->Form->control('user_id', [
        'class' => 'form-select select2-enable',
        'label' => ['text' => 'Usuario', 'class' => 'input-label'],
        'options' => $users,
        'empty' => '-- Seleccione usuario --',
    ]) ?>
</div>
<div class="mb-3">
    <?= $this->Form->control('operation_center_id', [
        'class' => 'form-select select2-enable',
        'label' => ['text' => 'Centro de Operación (opcional)', 'class' => 'input-label'],
        'options' => $operationCenters,
        'empty' => '-- Todos los centros --',
    ]) ?>
    <small class="text-muted">Si no selecciona, el aprobador aplica para todos los centros.</small>
</div>
<div class="mb-3">
    <div class="form-check">
        <?= $this->Form->checkbox('active', ['class' => 'form-check-input']) ?>
        <?= $this->Form->label('active', 'Activo', ['class' => 'form-check-label']) ?>
    </div>
</div>

<?php
/**
 * Campos del form de Cargo. Compartido por la página standalone
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
        ]) ?>
    </div>
    <div class="col-md-8">
        <?= $this->Form->control('name', [
            'class' => 'form-control',
            'label' => ['text' => 'Nombre', 'class' => 'input-label'],
        ]) ?>
    </div>
</div>

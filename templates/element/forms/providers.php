<?php
/**
 * Campos del form de Proveedor. Compartido por la página standalone
 * (add/edit) y el modal AJAX. Asume estar dentro de un Form->create($entity).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Provider $provider
 */
?>
<div class="row g-3">
    <div class="col-md-3">
        <?= $this->Form->control('document_type', [
            'class' => 'form-select',
            'label' => ['text' => 'Tipo de Documento', 'class' => 'input-label'],
            'options' => ['NIT' => 'NIT', 'CC' => 'CC', 'Otro' => 'Otro'],
            'default' => 'NIT',
        ]) ?>
    </div>
    <div class="col-md-3">
        <?= $this->Form->control('document_number', [
            'class' => 'form-control',
            'label' => ['text' => 'Número de Documento', 'class' => 'input-label'],
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('name', [
            'class' => 'form-control',
            'label' => ['text' => 'Nombre', 'class' => 'input-label'],
        ]) ?>
    </div>
</div>
<div class="mt-3">
    <div class="form-check">
        <?= $this->Form->checkbox('active', [
            'class' => 'form-check-input',
        ] + ($provider->isNew() ? ['checked' => true] : [])) ?>
        <?= $this->Form->label('active', 'Activo', ['class' => 'form-check-label']) ?>
    </div>
</div>

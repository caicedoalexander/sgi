<?php
$this->assign('title', 'Nueva Organización Temporal');
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>
<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Nueva Organización Temporal</h5></div>
    <div class="card-body">
        <?= $this->Form->create($temporaryOrganization) ?>
        <div class="row">
            <div class="col-md-6 mb-3">
                <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-4 mb-3">
                <?= $this->Form->control('nit', ['class' => 'form-control', 'label' => ['text' => 'NIT', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-2 mb-3">
                <?= $this->Form->control('active', ['class' => 'form-check-input', 'label' => ['text' => 'Activa', 'class' => 'form-check-label'], 'type' => 'checkbox', 'checked' => true]) ?>
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

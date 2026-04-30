<?php
$this->assign('title', 'Nueva Organización Temporal');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nueva Organización Temporal</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<div class="card card-primary">
<div class="card-body">
        <?= $this->Form->create($temporaryOrganization) ?>
        <div class="row">
            <div class="col-md-6 mb-3">
                <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-4 mb-3">
                <?= $this->Form->control('nit', ['class' => 'form-control', 'label' => ['text' => 'NIT', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-2 mb-3 d-flex align-items-end">
                <div class="form-check">
                    <?= $this->Form->checkbox('active', ['class' => 'form-check-input', 'checked' => true]) ?>
                    <?= $this->Form->label('active', 'Activa', ['class' => 'form-check-label']) ?>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

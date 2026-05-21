<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\TemporaryOrganization $temporaryOrganization
 */
$this->assign('title', 'Editar Organización Temporal');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Organización Temporal</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="sgi-card">
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
                <?= $this->Form->checkbox('active', ['class' => 'form-check-input']) ?>
                <?= $this->Form->label('active', 'Activa', ['class' => 'form-check-label']) ?>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
    <?= $this->Form->end() ?>
</div>

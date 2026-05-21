<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MaritalStatus $maritalStatus
 */
$this->assign('title', 'Editar Estado Civil');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Estado Civil</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="sgi-card">
    <?= $this->Form->create($maritalStatus) ?>
    <div class="row">
        <div class="col-md-8 mb-3">
            <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
        </div>
    </div>
    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
    <?= $this->Form->end() ?>
</div>

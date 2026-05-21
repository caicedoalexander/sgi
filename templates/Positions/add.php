<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Position $position
 */
$this->assign('title', 'Nuevo Cargo');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nuevo Cargo</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="sgi-card">
    <?= $this->Form->create($position) ?>
    <div class="row">
        <div class="col-md-4 mb-3">
            <?= $this->Form->control('code', ['class' => 'form-control', 'label' => ['text' => 'Código', 'class' => 'form-label']]) ?>
        </div>
        <div class="col-md-8 mb-3">
            <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
        </div>
    </div>
    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar</button>
    <?= $this->Form->end() ?>
</div>

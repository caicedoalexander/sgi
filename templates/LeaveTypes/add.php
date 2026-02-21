<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\LeaveType $leaveType
 */
$this->assign('title', 'Nuevo Tipo de Permiso');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nuevo Tipo de Permiso</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body p-4">
        <?= $this->Form->create($leaveType) ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Código</label>
                <?= $this->Form->control('code', ['label' => false, 'class' => 'form-control', 'placeholder' => 'VAC']) ?>
            </div>
            <div class="col-md-8">
                <label class="form-label">Nombre</label>
                <?= $this->Form->control('name', ['label' => false, 'class' => 'form-control', 'placeholder' => 'Vacaciones']) ?>
            </div>
        </div>
        <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

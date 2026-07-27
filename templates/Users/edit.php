<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var \Cake\Collection\CollectionInterface|string[] $roles
 */
$this->assign('title', 'Editar Usuario');
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Editar Usuario</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="spi-card">
    <?= $this->Form->create($user) ?>
    <div class="row">
        <div class="col-md-6 mb-3">
            <?= $this->Form->control('username', ['class' => 'form-control', 'label' => ['text' => 'Usuario', 'class' => 'input-label']]) ?>
        </div>
        <div class="col-md-6 mb-3">
            <?= $this->Form->control('password', ['class' => 'form-control', 'label' => ['text' => 'Contraseña (dejar vacío para no cambiar)', 'class' => 'input-label'], 'value' => '', 'required' => false]) ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <?= $this->Form->control('full_name', ['class' => 'form-control', 'label' => ['text' => 'Nombre Completo', 'class' => 'input-label']]) ?>
        </div>
        <div class="col-md-6 mb-3">
            <?= $this->Form->control('email', ['class' => 'form-control', 'label' => ['text' => 'Email', 'class' => 'input-label']]) ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <?= $this->Form->control('role_id', ['class' => 'form-select', 'label' => ['text' => 'Rol', 'class' => 'input-label'], 'options' => $roles, 'empty' => '-- Seleccione --']) ?>
        </div>
        <div class="col-md-6 mb-3 d-flex align-items-end">
            <div class="form-check">
                <?= $this->Form->checkbox('active', ['class' => 'form-check-input']) ?>
                <?= $this->Form->label('active', 'Activo', ['class' => 'form-check-label']) ?>
            </div>
        </div>
    </div>
    <div class="pt-3" style="border-top:1px solid var(--rule);">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
    </div>
    <?= $this->Form->end() ?>
</div>

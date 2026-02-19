<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var \Cake\Collection\CollectionInterface|string[] $roles
 */
$this->assign('title', 'Nuevo Usuario');
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Nuevo Usuario</h5></div>
    <div class="card-body">
        <?= $this->Form->create($user) ?>
        <div class="row">
            <div class="col-md-6 mb-3">
                <?= $this->Form->control('username', ['class' => 'form-control', 'label' => ['text' => 'Usuario', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-6 mb-3">
                <?= $this->Form->control('password', ['class' => 'form-control', 'label' => ['text' => 'Contraseña', 'class' => 'form-label']]) ?>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <?= $this->Form->control('full_name', ['class' => 'form-control', 'label' => ['text' => 'Nombre Completo', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-6 mb-3">
                <?= $this->Form->control('email', ['class' => 'form-control', 'label' => ['text' => 'Email', 'class' => 'form-label']]) ?>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <?= $this->Form->control('role_id', ['class' => 'form-select', 'label' => ['text' => 'Rol', 'class' => 'form-label'], 'options' => $roles, 'empty' => '-- Seleccione --']) ?>
            </div>
            <div class="col-md-6 mb-3">
                <?= $this->Form->control('active', ['class' => 'form-check-input', 'label' => ['text' => 'Activo', 'class' => 'form-check-label'], 'type' => 'checkbox']) ?>
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

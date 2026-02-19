<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 */
$this->assign('title', 'Usuario: ' . $user->full_name);
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Detalle del Usuario</h5></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">ID</dt>
            <dd class="col-sm-9"><?= $this->Number->format($user->id) ?></dd>
            <dt class="col-sm-3">Usuario</dt>
            <dd class="col-sm-9"><?= h($user->username) ?></dd>
            <dt class="col-sm-3">Nombre Completo</dt>
            <dd class="col-sm-9"><?= h($user->full_name) ?></dd>
            <dt class="col-sm-3">Email</dt>
            <dd class="col-sm-9"><?= h($user->email) ?></dd>
            <dt class="col-sm-3">Rol</dt>
            <dd class="col-sm-9"><?= $user->hasValue('role') ? h($user->role->name) : '' ?></dd>
            <dt class="col-sm-3">Estado</dt>
            <dd class="col-sm-9"><?= $user->active ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></dd>
            <dt class="col-sm-3">Creado</dt>
            <dd class="col-sm-9"><?= $user->created?->format('d/m/Y H:i') ?></dd>
            <dt class="col-sm-3">Modificado</dt>
            <dd class="col-sm-9"><?= $user->modified?->format('d/m/Y H:i') ?></dd>
        </dl>
    </div>
    <div class="card-footer">
        <?= $this->Html->link('<i class="bi bi-pencil me-1"></i>Editar', ['action' => 'edit', $user->id], ['class' => 'btn btn-warning btn-sm', 'escape' => false]) ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1"></i>Eliminar', ['action' => 'delete', $user->id], ['confirm' => '¿Está seguro?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
    </div>
</div>

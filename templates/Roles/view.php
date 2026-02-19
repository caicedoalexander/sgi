<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 */
$this->assign('title', 'Rol: ' . $role->name);
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header"><h5 class="mb-0">Detalle del Rol</h5></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">ID</dt>
            <dd class="col-sm-9"><?= $this->Number->format($role->id) ?></dd>
            <dt class="col-sm-3">Nombre</dt>
            <dd class="col-sm-9"><?= h($role->name) ?></dd>
            <dt class="col-sm-3">Descripción</dt>
            <dd class="col-sm-9"><?= h($role->description) ?: '<span class="text-muted">—</span>' ?></dd>
            <dt class="col-sm-3">Creado</dt>
            <dd class="col-sm-9"><?= $role->created?->format('d/m/Y H:i') ?></dd>
            <dt class="col-sm-3">Modificado</dt>
            <dd class="col-sm-9"><?= $role->modified?->format('d/m/Y H:i') ?></dd>
        </dl>
    </div>
    <div class="card-footer">
        <?= $this->Html->link('<i class="bi bi-pencil me-1"></i>Editar', ['action' => 'edit', $role->id], ['class' => 'btn btn-warning btn-sm', 'escape' => false]) ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1"></i>Eliminar', ['action' => 'delete', $role->id], ['confirm' => '¿Está seguro?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
    </div>
</div>

<?php if (!empty($role->users)): ?>
<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Usuarios con este rol</h5></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Usuario</th>
                    <th>Nombre Completo</th>
                    <th>Email</th>
                    <th>Activo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($role->users as $user): ?>
                <tr>
                    <td><?= $this->Html->link(h($user->username), ['controller' => 'Users', 'action' => 'view', $user->id]) ?></td>
                    <td><?= h($user->full_name) ?></td>
                    <td><?= h($user->email) ?></td>
                    <td><?= $user->active ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

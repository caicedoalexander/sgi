<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\User> $users
 */
$this->assign('title', 'Usuarios');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Usuarios</span>
    <?php if (!empty($userPermissions['users']['can_create'])): ?>
    <?= $this->Html->link('<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Usuario', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
    <?php endif; ?>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th><?= $this->Paginator->sort('username', 'Usuario') ?></th>
                    <th><?= $this->Paginator->sort('full_name', 'Nombre Completo') ?></th>
                    <th><?= $this->Paginator->sort('email', 'Email') ?></th>
                    <th><?= $this->Paginator->sort('Roles.name', 'Rol') ?></th>
                    <th><?= $this->Paginator->sort('active', 'Estado') ?></th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= $this->Number->format($user->id) ?></td>
                    <td><?= h($user->username) ?></td>
                    <td><?= h($user->full_name) ?></td>
                    <td><?= h($user->email) ?></td>
                    <td><?= $user->hasValue('role') ? h($user->role->name) : '' ?></td>
                    <td><?= $user->active ? '<span class="pill pill-primary-soft">Activo</span>' : '<span class="pill pill-secondary-soft">Inactivo</span>' ?></td>
                    <td class="text-end">
                        <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>', ['action' => 'view', $user->id], ['class' => 'btn btn-sm btn-outline-info', 'escape' => false, 'title' => 'Ver']) ?>
                        <?php if (!empty($userPermissions['users']['can_edit'])): ?>
                        <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>', ['action' => 'edit', $user->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?php endif; ?>
                        <?php if (!empty($userPermissions['users']['can_delete'])): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>', ['action' => 'delete', $user->id], ['confirm' => '¿Está seguro de eliminar este usuario?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>

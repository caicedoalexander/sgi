<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\User> $users
 */
$this->assign('title', 'Usuarios');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Usuarios</h1>
    <?= $this->Html->link('<i class="bi bi-plus-lg me-1"></i>Nuevo Usuario', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
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
                    <td><?= $user->active ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
                    <td class="text-end">
                        <?= $this->Html->link('<i class="bi bi-eye"></i>', ['action' => 'view', $user->id], ['class' => 'btn btn-sm btn-outline-info', 'escape' => false, 'title' => 'Ver']) ?>
                        <?= $this->Html->link('<i class="bi bi-pencil"></i>', ['action' => 'edit', $user->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'delete', $user->id], ['confirm' => '¿Está seguro de eliminar este usuario?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted"><?= $this->Paginator->counter('Mostrando {{start}}-{{end}} de {{count}}') ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?= $this->Paginator->first('«', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->prev('‹', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->numbers(['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->next('›', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->last('»', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
            </ul>
        </nav>
    </div>
</div>

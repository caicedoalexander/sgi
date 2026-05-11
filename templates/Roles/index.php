<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Role> $roles
 */
$this->assign('title', 'Roles');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Roles</span>
    <?php if (!empty($userPermissions['roles']['can_create'])): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Rol',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
    <?php endif; ?>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th><?= $this->Paginator->sort('description', 'Descripción') ?></th>
                    <th><?= $this->Paginator->sort('created', 'Creado') ?></th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                <tr>
                    <td><?= $this->Number->format($role->id) ?></td>
                    <td><?= h($role->name) ?></td>
                    <td><?= h($role->description) ?></td>
                    <td><?= $role->created?->format('d/m/Y H:i') ?></td>
                    <td class="text-end">
                        <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>', ['action' => 'view', $role->id], ['class' => 'btn btn-sm btn-outline-info', 'escape' => false, 'title' => 'Ver']) ?>
                        <?php if (!empty($userPermissions['roles']['can_edit'])): ?>
                        <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>', ['action' => 'edit', $role->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?php endif; ?>
                        <?php if (!empty($userPermissions['roles']['can_delete'])): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>', ['action' => 'delete', $role->id], ['confirm' => '¿Está seguro de eliminar este rol?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\User> $users
 */
$this->assign('title', 'Usuarios');

$canCreate = !empty($userPermissions['users']['can_create']);
$canEdit   = !empty($userPermissions['users']['can_edit']);
$canDelete = !empty($userPermissions['users']['can_delete']);

// # · Usuario · Nombre Completo · Email · Rol · Estado · Acciones
$gridCols = '60px 1fr 1.4fr 1.6fr 1fr 100px 110px';
?>
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div>
        <span class="sgi-page-title">Usuarios</span>
        <div class="sgi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            <span class="sgi-fg-muted"><?= $this->Paginator->counter('{{count}} usuarios') ?></span>
        </div>
    </div>
    <?php if ($canCreate): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Usuario',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
    <?php endif; ?>
</div>

<div class="sgi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span><?= $this->Paginator->sort('id', '#') ?></span>
        <span><?= $this->Paginator->sort('username', 'Usuario') ?></span>
        <span><?= $this->Paginator->sort('full_name', 'Nombre Completo') ?></span>
        <span><?= $this->Paginator->sort('email', 'Email') ?></span>
        <span><?= $this->Paginator->sort('Roles.name', 'Rol') ?></span>
        <span><?= $this->Paginator->sort('active', 'Estado') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($users as $user): $rowCount++; ?>
    <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= $this->Url->build(['action' => 'view', $user->id]) ?>" role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($user->id) ?></span>
        <span class="mono" style="color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($user->username) ?></span>
        <span style="font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($user->full_name) ?></span>
        <span style="color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($user->email) ?></span>
        <span style="color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $user->hasValue('role') ? h($user->role->name) : '—' ?></span>
        <span>
            <?php if ($user->active): ?>
            <span class="pill pill-sm pill-primary-soft">Activo</span>
            <?php else: ?>
            <span class="pill pill-sm pill-secondary-soft">Inactivo</span>
            <?php endif; ?>
        </span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $user->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver']) ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $user->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $user->id],
                ['confirm' => '¿Está seguro de eliminar este usuario?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-people" aria-hidden="true"></i></div>
        <div class="es-title">Sin usuarios</div>
        <div class="es-msg">No hay usuarios para mostrar todavía.</div>
    </div>
    <?php endif; ?>

    <?= $this->element('pagination') ?>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 */
$this->assign('title', 'Usuario: ' . $user->full_name);

$canEdit   = !empty($userPermissions['users']['can_edit']);
$canDelete = !empty($userPermissions['users']['can_delete']);
?>
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div>
        <span class="sgi-page-title">Detalle del Usuario</span>
        <div class="sgi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            <span class="mono sgi-fg-muted"><?= h($user->username) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]
        ) ?>
        <?php if ($canDelete): ?>
        <?= $this->Form->postLink(
            '<i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar',
            ['action' => 'delete', $user->id],
            ['confirm' => '¿Está seguro de eliminar este usuario?', 'class' => 'btn btn-default btn-sm sgi-fg-danger', 'escape' => false]
        ) ?>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $user->id],
            ['class' => 'btn btn-primary btn-sm', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="sgi-card">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 28px;">
        <div class="field-row"><span class="k">ID</span><span class="v mono"><?= $this->Number->format($user->id) ?></span></div>
        <div class="field-row"><span class="k">Usuario</span><span class="v mono"><?= h($user->username) ?></span></div>
        <div class="field-row"><span class="k">Nombre Completo</span><span class="v"><?= h($user->full_name) ?></span></div>
        <div class="field-row"><span class="k">Email</span><span class="v"><?= h($user->email) ?></span></div>
        <div class="field-row"><span class="k">Rol</span><span class="v"><?= $user->hasValue('role') ? h($user->role->name) : '—' ?></span></div>
        <div class="field-row">
            <span class="k">Estado</span>
            <span class="v"><?= $user->active
                ? '<span class="pill pill-sm pill-primary-soft">Activo</span>'
                : '<span class="pill pill-sm pill-secondary-soft">Inactivo</span>' ?></span>
        </div>
        <div class="field-row"><span class="k">Creado</span><span class="v mono"><?= $user->created?->format('d/m/Y H:i') ?? '—' ?></span></div>
        <div class="field-row"><span class="k">Modificado</span><span class="v mono"><?= $user->modified?->format('d/m/Y H:i') ?? '—' ?></span></div>
    </div>
</div>

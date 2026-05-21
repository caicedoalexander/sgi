<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\TemporaryOrganization> $temporaryOrganizations
 */
$this->assign('title', 'Organizaciones Temporales');

$canEdit   = !empty($userPermissions['temporary_organizations']['can_edit']);
$canDelete = !empty($userPermissions['temporary_organizations']['can_delete']);
$gridCols  = '80px 1fr 180px 120px 96px';
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Organizaciones Temporales</span>
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'TemporaryOrganizations',
            'importable' => true,
            'canCreate' => !empty($userPermissions['temporary_organizations']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['temporary_organizations']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Organización',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="sgi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span><?= $this->Paginator->sort('id', '#') ?></span>
        <span><?= $this->Paginator->sort('name', 'Nombre') ?></span>
        <span><?= $this->Paginator->sort('nit', 'NIT') ?></span>
        <span><?= $this->Paginator->sort('active', 'Estado') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($temporaryOrganizations as $org): $rowCount++; ?>
    <div class="row-fact<?= $canEdit ? ' clickable-row' : '' ?>"
         style="grid-template-columns:<?= $gridCols ?>;"
         <?= $canEdit ? 'data-href="' . $this->Url->build(['action' => 'edit', $org->id]) . '"' : '' ?>
         role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($org->id) ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($org->name) ?></span>
        <span class="mono" style="color:var(--text-muted);"><?= h($org->nit) ?></span>
        <span>
            <?php if ($org->active): ?>
                <span class="pill pill-primary-soft">Activa</span>
            <?php else: ?>
                <span class="pill pill-secondary-soft">Inactiva</span>
            <?php endif; ?>
        </span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $org->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $org->id],
                ['confirm' => '¿Está seguro de eliminar?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-building-gear" aria-hidden="true"></i></div>
        <div class="es-title">Sin organizaciones temporales</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

<?= $this->element('excel_wizard/modals', [
    'module' => 'TemporaryOrganizations',
    'entityName' => 'Organizaciones Temporales',
    'downloadSlug' => 'temporales',
    'importable' => true,
]) ?>

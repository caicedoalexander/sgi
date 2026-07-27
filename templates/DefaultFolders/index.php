<?php
$this->assign('title', 'Carpetas por Defecto');

$canEdit   = !empty($userPermissions['default_folders']['can_edit']);
$canDelete = !empty($userPermissions['default_folders']['can_delete']);
$gridCols  = '80px 1fr 120px 96px';
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Carpetas por Defecto</span>
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'DefaultFolders',
            'importable' => true,
            'canCreate' => !empty($userPermissions['default_folders']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['default_folders']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Carpeta por Defecto',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false, 'data-catalog-modal' => 'true']
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span><?= $this->Paginator->sort('id', '#') ?></span>
        <span><?= $this->Paginator->sort('name', 'Nombre') ?></span>
        <span><?= $this->Paginator->sort('sort_order', 'Orden') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($defaultFolders as $defaultFolder): $rowCount++; ?>
    <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= $this->Url->build(['action' => 'view', $defaultFolder->id]) ?>" role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($defaultFolder->id) ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($defaultFolder->name) ?></span>
        <span class="mono" style="color:var(--text-muted);"><?= $this->Number->format($defaultFolder->sort_order) ?></span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $defaultFolder->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver']) ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $defaultFolder->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar', 'data-catalog-modal' => 'true']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $defaultFolder->id],
                ['confirm' => '¿Está seguro de eliminar?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-folder" aria-hidden="true"></i></div>
        <div class="es-title">Sin carpetas por defecto</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

<?= $this->element('excel_wizard/modals', [
    'module' => 'DefaultFolders',
    'entityName' => 'Carpetas por Defecto',
    'downloadSlug' => 'carpetas_defecto',
    'importable' => true,
]) ?>

<?= $this->element('catalog_modal') ?>

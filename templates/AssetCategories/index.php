<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\AssetCategory> $assetCategories
 */
$this->assign('title', 'Categorías de Activos');

$canEdit = !empty($userPermissions['asset_categories']['can_edit']);
$canDelete = !empty($userPermissions['asset_categories']['can_delete']);
$gridCols = '80px 140px 1fr 90px 96px';
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Categorías de Activos</span>
    <?php if (!empty($userPermissions['asset_categories']['can_create'])): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Categoría',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false, 'data-catalog-modal' => 'true']
    ) ?>
    <?php endif; ?>
</div>

<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span><?= $this->Paginator->sort('id', '#') ?></span>
        <span><?= $this->Paginator->sort('code', 'Código') ?></span>
        <span><?= $this->Paginator->sort('name', 'Nombre') ?></span>
        <span><?= $this->Paginator->sort('active', 'Estado') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($assetCategories as $category): $rowCount++; ?>
    <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= h($this->Url->build(['action' => 'view', $category->id])) ?>" role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($category->id) ?></span>
        <span class="mono" style="color:var(--text-muted);"><?= h($category->code) ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($category->name) ?></span>
        <span>
            <?php if ($category->active): ?>
                <span class="pill pill-accent-soft">Activa</span>
            <?php else: ?>
                <span class="pill pill-secondary-soft">Inactiva</span>
            <?php endif; ?>
        </span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $category->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver']) ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $category->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar', 'data-catalog-modal' => 'true']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $category->id],
                ['confirm' => '¿Está seguro de eliminar?', 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-tags" aria-hidden="true"></i></div>
        <div class="es-title">Sin categorías</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>
<?= $this->element('catalog_modal') ?>

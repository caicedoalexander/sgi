<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AssetCategory $assetCategory
 */
$this->assign('title', 'Categoría: ' . h($assetCategory->name));
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Detalle de la Categoría</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['asset_categories']['can_edit'])): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $assetCategory->id],
            ['class' => 'btn btn-primary btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="spi-card">
    <div class="field-row">
        <span class="k">Código</span>
        <span class="v mono"><?= h($assetCategory->code) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Nombre</span>
        <span class="v"><?= h($assetCategory->name) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Descripción</span>
        <span class="v"><?= h($assetCategory->description) ?: '—' ?></span>
    </div>
    <div class="field-row is-last">
        <span class="k">Estado</span>
        <span class="v">
            <?php if ($assetCategory->active): ?>
                <span class="pill pill-accent-soft">Activa</span>
            <?php else: ?>
                <span class="pill pill-secondary-soft">Inactiva</span>
            <?php endif; ?>
        </span>
    </div>
</div>

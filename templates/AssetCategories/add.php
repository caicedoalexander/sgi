<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AssetCategory $assetCategory
 */
$this->assign('title', 'Nueva Categoría');

if ($this->request->is('ajax')) {
    echo $this->element('catalog_modal_form', [
        'entity' => $assetCategory,
        'fieldsElement' => 'forms/asset_categories',
        'title' => 'Nueva Categoría',
        'submitLabel' => 'Guardar',
    ]);

    return;
}
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Nueva Categoría</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="spi-card">
    <?= $this->Form->create($assetCategory) ?>
    <?= $this->element('forms/asset_categories') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar</button>
    <?= $this->Form->end() ?>
</div>

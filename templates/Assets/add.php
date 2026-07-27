<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Asset $asset
 */
$this->assign('title', 'Nuevo Activo');
?>
<?= $this->element('cdn_select2') ?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Nuevo Activo</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="spi-card">
    <?= $this->Form->create($asset) ?>
    <?= $this->element('forms/assets') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Crear activo</button>
    <?= $this->Form->end() ?>
</div>

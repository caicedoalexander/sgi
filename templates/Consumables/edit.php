<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Consumable $consumable
 */
$this->assign('title', 'Editar Consumible');
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Editar Consumible <?= h($consumable->reference) ?></span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'view', $consumable->id], ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>
<div class="spi-card">
    <?= $this->Form->create($consumable) ?>
    <?= $this->element('forms/consumables') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar cambios</button>
    <?= $this->Form->end() ?>
</div>

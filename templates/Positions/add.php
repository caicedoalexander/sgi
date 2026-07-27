<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Position $position
 */
$this->assign('title', 'Nuevo Cargo');

// Render AJAX (modal): solo el fragmento del form.
if ($this->request->is('ajax')) {
    echo $this->element('catalog_modal_form', [
        'entity' => $position,
        'fieldsElement' => 'forms/positions',
        'title' => 'Nuevo Cargo',
        'submitLabel' => 'Guardar',
    ]);

    return;
}
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Nuevo Cargo</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="spi-card">
    <?= $this->Form->create($position) ?>
    <?= $this->element('forms/positions') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar</button>
    <?= $this->Form->end() ?>
</div>

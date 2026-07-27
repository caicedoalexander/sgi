<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\DefaultFolder $defaultFolder
 */
$this->assign('title', 'Nueva Carpeta por Defecto');

// Render AJAX (modal): solo el fragmento del form.
if ($this->request->is('ajax')) {
    echo $this->element('catalog_modal_form', [
        'entity' => $defaultFolder,
        'fieldsElement' => 'forms/default_folders',
        'title' => 'Nueva Carpeta por Defecto',
        'submitLabel' => 'Guardar',
    ]);

    return;
}
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Nueva Carpeta por Defecto</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="spi-card">
    <?= $this->Form->create($defaultFolder) ?>
    <?= $this->element('forms/default_folders') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar</button>
    <?= $this->Form->end() ?>
</div>

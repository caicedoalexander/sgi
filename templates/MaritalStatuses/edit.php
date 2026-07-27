<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MaritalStatus $maritalStatus
 */
$this->assign('title', 'Editar Estado Civil');

// Render AJAX (modal): solo el fragmento del form.
if ($this->request->is('ajax')) {
    echo $this->element('catalog_modal_form', [
        'entity' => $maritalStatus,
        'fieldsElement' => 'forms/marital_statuses',
        'title' => 'Editar Estado Civil',
        'submitLabel' => 'Actualizar',
    ]);

    return;
}
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Editar Estado Civil</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="spi-card">
    <?= $this->Form->create($maritalStatus) ?>
    <?= $this->element('forms/marital_statuses') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
    <?= $this->Form->end() ?>
</div>

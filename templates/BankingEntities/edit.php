<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\BankingEntity $bankingEntity
 */
$this->assign('title', 'Editar Entidad Bancaria');

// Render AJAX (modal): solo el fragmento del form.
if ($this->request->is('ajax')) {
    echo $this->element('catalog_modal_form', [
        'entity' => $bankingEntity,
        'fieldsElement' => 'forms/banking_entities',
        'title' => 'Editar Entidad Bancaria',
        'submitLabel' => 'Actualizar',
    ]);

    return;
}
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Editar Entidad Bancaria</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="spi-card">
    <?= $this->Form->create($bankingEntity) ?>
    <?= $this->element('forms/banking_entities') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
    <?= $this->Form->end() ?>
</div>

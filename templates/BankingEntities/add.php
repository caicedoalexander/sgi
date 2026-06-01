<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\BankingEntity $bankingEntity
 */
$this->assign('title', 'Nueva Entidad Bancaria');

// Render AJAX (modal): solo el fragmento del form.
if ($this->request->is('ajax')) {
    echo $this->element('catalog_modal_form', [
        'entity' => $bankingEntity,
        'fieldsElement' => 'forms/banking_entities',
        'title' => 'Nueva Entidad Bancaria',
        'submitLabel' => 'Guardar',
    ]);

    return;
}
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nueva Entidad Bancaria</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="sgi-card">
    <?= $this->Form->create($bankingEntity) ?>
    <?= $this->element('forms/banking_entities') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar</button>
    <?= $this->Form->end() ?>
</div>

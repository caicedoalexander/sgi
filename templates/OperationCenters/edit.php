<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\OperationCenter $operationCenter
 */
$this->assign('title', 'Editar Centro de Operación');

// Render AJAX (modal): solo el fragmento del form.
if ($this->request->is('ajax')) {
    echo $this->element('catalog_modal_form', [
        'entity' => $operationCenter,
        'fieldsElement' => 'forms/operation_centers',
        'title' => 'Editar Centro de Operación',
        'submitLabel' => 'Actualizar',
    ]);

    return;
}
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Centro de Operación</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="sgi-card">
    <?= $this->Form->create($operationCenter) ?>
    <?= $this->element('forms/operation_centers') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
    <?= $this->Form->end() ?>
</div>

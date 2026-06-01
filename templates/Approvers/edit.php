<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Approver $approver
 * @var iterable $users
 * @var iterable $operationCenters
 */
$this->assign('title', 'Editar Aprobador');

// Render AJAX (modal): solo el fragmento del form.
if ($this->request->is('ajax')) {
    echo $this->element('catalog_modal_form', [
        'entity' => $approver,
        'fieldsElement' => 'forms/approvers',
        'title' => 'Editar Aprobador',
        'submitLabel' => 'Actualizar',
    ]);

    return;
}
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Aprobador</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="sgi-card" style="max-width:600px;">
    <?= $this->Form->create($approver) ?>
    <?= $this->element('forms/approvers') ?>
    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
    <?= $this->Form->end() ?>
</div>

<?php
/**
 * Contenido del modal de catálogo: form (add/edit) renderizado para inyección
 * AJAX dentro de #catalogModalContent. El action del form lo resuelve
 * Form->create($entity) (add vs edit según isNew()).
 *
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\EntityInterface $entity
 * @var string $fieldsElement Element con los campos del catálogo (p.ej. 'forms/operation_centers').
 * @var string $title Título del modal.
 * @var string $submitLabel Texto del botón de guardar.
 * @var array $formOptions Opciones extra para Form->create (p.ej. ['type' => 'file']).
 */
$formOptions = ($formOptions ?? []) + ['id' => 'catalog-modal-form'];
?>
<?= $this->Form->create($entity, $formOptions) ?>
<div class="modal-header">
    <h5 class="modal-title"><?= h($title) ?></h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
</div>
<div class="modal-body">
    <?= $this->element($fieldsElement) ?>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-ghost-card" data-bs-dismiss="modal">Cancelar</button>
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1" aria-hidden="true"></i><?= h($submitLabel) ?>
    </button>
</div>
<?= $this->Form->end() ?>

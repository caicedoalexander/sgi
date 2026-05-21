<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MaritalStatus> $maritalStatuses
 */
$this->assign('title', 'Estados Civiles');

$canEdit   = !empty($userPermissions['marital_statuses']['can_edit']);
$canDelete = !empty($userPermissions['marital_statuses']['can_delete']);
$gridCols  = '80px 1fr 96px';
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Estados Civiles</span>
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'MaritalStatuses',
            'importable' => true,
            'canCreate' => !empty($userPermissions['marital_statuses']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['marital_statuses']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Estado Civil',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="sgi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span><?= $this->Paginator->sort('id', '#') ?></span>
        <span><?= $this->Paginator->sort('name', 'Nombre') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($maritalStatuses as $maritalStatus): $rowCount++; ?>
    <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= $this->Url->build(['action' => 'view', $maritalStatus->id]) ?>" role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($maritalStatus->id) ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($maritalStatus->name) ?></span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $maritalStatus->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver']) ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $maritalStatus->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $maritalStatus->id],
                ['confirm' => '¿Está seguro de eliminar?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-heart" aria-hidden="true"></i></div>
        <div class="es-title">Sin estados civiles</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

<?= $this->element('excel_wizard/modals', [
    'module' => 'MaritalStatuses',
    'entityName' => 'Estados Civiles',
    'downloadSlug' => 'estados_civiles',
    'importable' => true,
]) ?>

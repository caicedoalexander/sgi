<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\OperationCenter> $operationCenters
 */
$this->assign('title', 'Centros de Operación');

$canEdit   = !empty($userPermissions['operation_centers']['can_edit']);
$canDelete = !empty($userPermissions['operation_centers']['can_delete']);
$gridCols  = '80px 120px 1fr 200px 96px';
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Centros de Operación</span>
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'OperationCenters',
            'importable' => true,
            'canCreate' => !empty($userPermissions['operation_centers']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['operation_centers']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Centro',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="sgi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span><?= $this->Paginator->sort('id', '#') ?></span>
        <span><?= $this->Paginator->sort('code', 'Código') ?></span>
        <span><?= $this->Paginator->sort('name', 'Nombre') ?></span>
        <span><?= $this->Paginator->sort('created', 'Creado') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($operationCenters as $operationCenter): $rowCount++; ?>
    <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= $this->Url->build(['action' => 'view', $operationCenter->id]) ?>" role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($operationCenter->id) ?></span>
        <span class="mono" style="color:var(--text-strong);"><?= h($operationCenter->code ?? '-') ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($operationCenter->name) ?></span>
        <span class="mono" style="color:var(--text-muted);"><?= $operationCenter->created?->format('d/m/Y H:i') ?></span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $operationCenter->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver']) ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $operationCenter->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $operationCenter->id],
                ['confirm' => '¿Está seguro de eliminar este centro de operación?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-geo-alt" aria-hidden="true"></i></div>
        <div class="es-title">Sin centros de operación</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

<?= $this->element('excel_wizard/modals', [
    'module' => 'OperationCenters',
    'entityName' => 'Centros de Operación',
    'downloadSlug' => 'centros_operacion',
    'importable' => true,
]) ?>

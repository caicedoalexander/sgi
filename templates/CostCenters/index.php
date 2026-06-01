<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\CostCenter> $costCenters
 */
$this->assign('title', 'Centros de Costos');

$canEdit   = !empty($userPermissions['cost_centers']['can_edit']);
$canDelete = !empty($userPermissions['cost_centers']['can_delete']);
$gridCols  = '80px 160px 1fr 200px 96px';
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Centros de Costos</span>
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'CostCenters',
            'importable' => true,
            'canCreate' => !empty($userPermissions['cost_centers']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['cost_centers']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Centro',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false, 'data-catalog-modal' => 'true']
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

    <?php $rowCount = 0; foreach ($costCenters as $costCenter): $rowCount++; ?>
    <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= $this->Url->build(['action' => 'view', $costCenter->id]) ?>" role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($costCenter->id) ?></span>
        <span class="mono" style="color:var(--text-muted);"><?= h($costCenter->code ?? '-') ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($costCenter->name) ?></span>
        <span class="mono" style="color:var(--text-muted);"><?= $costCenter->created?->format('d/m/Y H:i') ?></span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $costCenter->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver']) ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $costCenter->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar', 'data-catalog-modal' => 'true']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $costCenter->id],
                ['confirm' => '¿Está seguro de eliminar este centro de costos?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-diagram-3" aria-hidden="true"></i></div>
        <div class="es-title">Sin centros de costos</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

<?= $this->element('excel_wizard/modals', [
    'module' => 'CostCenters',
    'entityName' => 'Centros de Costos',
    'downloadSlug' => 'centros_costos',
    'importable' => true,
]) ?>

<?= $this->element('catalog_modal') ?>

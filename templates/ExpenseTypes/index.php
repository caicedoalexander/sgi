<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\ExpenseType> $expenseTypes
 */
$this->assign('title', 'Tipos de Gasto');

$canEdit   = !empty($userPermissions['expense_types']['can_edit']);
$canDelete = !empty($userPermissions['expense_types']['can_delete']);
$gridCols  = '80px 1fr 200px 96px';
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Tipos de Gasto</span>
    <?php if (!empty($userPermissions['expense_types']['can_create'])): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Tipo',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
    <?php endif; ?>
</div>

<div class="sgi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span><?= $this->Paginator->sort('id', '#') ?></span>
        <span><?= $this->Paginator->sort('name', 'Nombre') ?></span>
        <span><?= $this->Paginator->sort('created', 'Creado') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($expenseTypes as $expenseType): $rowCount++; ?>
    <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= $this->Url->build(['action' => 'view', $expenseType->id]) ?>" role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($expenseType->id) ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($expenseType->name) ?></span>
        <span class="mono" style="color:var(--text-muted);"><?= $expenseType->created?->format('d/m/Y H:i') ?></span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $expenseType->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver']) ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $expenseType->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $expenseType->id],
                ['confirm' => '¿Está seguro de eliminar este tipo de gasto?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-tags" aria-hidden="true"></i></div>
        <div class="es-title">Sin tipos de gasto</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

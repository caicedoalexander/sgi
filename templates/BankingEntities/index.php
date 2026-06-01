<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\BankingEntity> $bankingEntities
 */
$this->assign('title', 'Entidades Bancarias');

$canEdit   = !empty($userPermissions['banking_entities']['can_edit']);
$canDelete = !empty($userPermissions['banking_entities']['can_delete']);
$gridCols  = '80px 120px 1fr 130px 200px 96px';
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Entidades Bancarias</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['banking_entities']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Entidad',
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
        <span><?= $this->Paginator->sort('active', 'Estado') ?></span>
        <span><?= $this->Paginator->sort('created', 'Creado') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($bankingEntities as $entity): $rowCount++; ?>
    <?php $rowAttrs = $canEdit ? ' data-href="' . $this->Url->build(['action' => 'edit', $entity->id]) . '"' : ''; ?>
    <div class="row-fact<?= $canEdit ? ' clickable-row' : '' ?>" style="grid-template-columns:<?= $gridCols ?>;"<?= $rowAttrs ?> role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($entity->id) ?></span>
        <span class="mono" style="color:var(--text-muted);"><?= h($entity->code) ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($entity->name) ?></span>
        <span>
            <?php if ($entity->active): ?>
                <span class="pill pill-primary-soft">Activo</span>
            <?php else: ?>
                <span class="pill pill-secondary-soft">Inactivo</span>
            <?php endif; ?>
        </span>
        <span class="mono" style="color:var(--text-muted);"><?= $entity->created?->format('d/m/Y H:i') ?></span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $entity->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar', 'data-catalog-modal' => 'true']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $entity->id],
                ['confirm' => '¿Está seguro de eliminar esta entidad bancaria?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-bank" aria-hidden="true"></i></div>
        <div class="es-title">Sin entidades bancarias</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

<?= $this->element('catalog_modal') ?>

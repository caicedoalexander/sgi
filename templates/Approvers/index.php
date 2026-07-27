<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Approver> $approvers
 */
$this->assign('title', 'Aprobadores');

$canEdit   = !empty($userPermissions['approvers']['can_edit']);
$canDelete = !empty($userPermissions['approvers']['can_delete']);
$gridCols  = '80px 1fr 1fr 120px 96px';
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Aprobadores</span>
    <?php if (!empty($userPermissions['approvers']['can_create'])): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Aprobador',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false, 'data-catalog-modal' => 'true']
    ) ?>
    <?php endif; ?>
</div>

<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>#</span>
        <span>Usuario</span>
        <span>Centro de Operación</span>
        <span>Activo</span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($approvers as $approver): $rowCount++; ?>
    <?php
        $rowAttrs = '';
        if ($canEdit) {
            $rowAttrs = ' data-href="' . $this->Url->build(['action' => 'edit', $approver->id]) . '"';
        }
    ?>
    <div class="row-fact<?= $canEdit ? ' clickable-row' : '' ?>" style="grid-template-columns:<?= $gridCols ?>;"<?= $rowAttrs ?> role="row">
        <span class="mono" style="color:var(--text-faint);"><?= h($approver->id) ?></span>
        <span style="font-weight:600;color:var(--text-strong);">
            <?= $approver->hasValue('user') ? h($approver->user->full_name) : '' ?>
        </span>
        <span>
            <?= $approver->hasValue('operation_center') ? h($approver->operation_center->name) : '<span style="color:var(--text-disabled);">Todos</span>' ?>
        </span>
        <span>
            <?php if ($approver->active): ?>
                <span class="pill pill-primary-soft">Activo</span>
            <?php else: ?>
                <span class="pill pill-secondary-soft">Inactivo</span>
            <?php endif; ?>
        </span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $approver->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar', 'data-catalog-modal' => 'true']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $approver->id],
                ['confirm' => '¿Eliminar este aprobador?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-person-check" aria-hidden="true"></i></div>
        <div class="es-title">Sin aprobadores</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

<?= $this->element('catalog_modal', ['withSelect2' => true]) ?>

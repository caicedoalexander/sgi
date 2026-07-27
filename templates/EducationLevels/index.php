<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\EducationLevel> $educationLevels
 */
$this->assign('title', 'Niveles Educativos');

$canEdit   = !empty($userPermissions['education_levels']['can_edit']);
$canDelete = !empty($userPermissions['education_levels']['can_delete']);
$gridCols  = '80px 1fr 96px';
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Niveles Educativos</span>
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'EducationLevels',
            'importable' => true,
            'canCreate' => !empty($userPermissions['education_levels']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['education_levels']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Nivel Educativo',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false, 'data-catalog-modal' => 'true']
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span><?= $this->Paginator->sort('id', '#') ?></span>
        <span><?= $this->Paginator->sort('name', 'Nombre') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($educationLevels as $educationLevel): $rowCount++; ?>
    <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= $this->Url->build(['action' => 'view', $educationLevel->id]) ?>" role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($educationLevel->id) ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($educationLevel->name) ?></span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $educationLevel->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver']) ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $educationLevel->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar', 'data-catalog-modal' => 'true']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $educationLevel->id],
                ['confirm' => '¿Está seguro de eliminar?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-mortarboard" aria-hidden="true"></i></div>
        <div class="es-title">Sin niveles educativos</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

<?= $this->element('excel_wizard/modals', [
    'module' => 'EducationLevels',
    'entityName' => 'Niveles Educativos',
    'downloadSlug' => 'niveles_educativos',
    'importable' => true,
]) ?>

<?= $this->element('catalog_modal') ?>

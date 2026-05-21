<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\NoveltyType> $noveltyTypes
 */
$this->assign('title', 'Tipos de Novedad');

$canCreate = !empty($userPermissions['novelty_types']['can_create']);
$canEdit   = !empty($userPermissions['novelty_types']['can_edit']);
$canDelete = !empty($userPermissions['novelty_types']['can_delete']);
$gridCols  = '1fr 2fr 180px 148px';
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Tipos de Novedad</span>
    <?php if ($canCreate): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Tipo',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
    <?php endif; ?>
</div>

<div class="sgi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Nombre</span>
        <span>Subtipos</span>
        <span>Plantilla</span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($noveltyTypes as $noveltyType): $rowCount++; ?>
    <div class="row-fact<?= $canEdit ? ' clickable-row' : '' ?>"
         style="grid-template-columns:<?= $gridCols ?>;"
         <?php if ($canEdit): ?>data-href="<?= $this->Url->build(['action' => 'edit', $noveltyType->id]) ?>"<?php endif; ?>
         role="row">
        <span style="font-weight:600;color:var(--text-strong);"><?= h($noveltyType->name) ?></span>
        <span>
            <?php if (!empty($noveltyType->child_novelty_types)): ?>
                <?php foreach ($noveltyType->child_novelty_types as $child): ?>
                    <?php if ($canEdit): ?>
                        <?= $this->Html->link(
                            h($child->name) . ' <i class="bi bi-pencil-fill" style="font-size:var(--fs-label);" aria-hidden="true"></i>',
                            ['action' => 'edit', $child->id],
                            ['class' => 'pill pill-muted me-1 text-decoration-none', 'escape' => false, 'title' => 'Editar subtipo']
                        ) ?>
                    <?php else: ?>
                        <span class="pill pill-muted me-1"><?= h($child->name) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <span style="color:var(--text-disabled);">—</span>
            <?php endif; ?>
        </span>
        <span>
            <?php if (!empty($noveltyType->novelty_type_contract_templates)): ?>
                <span style="color:var(--text-muted);">
                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i><?= count($noveltyType->novelty_type_contract_templates) ?> asignación(es)
                </span>
            <?php else: ?>
                <span style="color:var(--text-disabled);">—</span>
            <?php endif; ?>
        </span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?php if ($canCreate): ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-circle" aria-hidden="true"></i>',
                ['action' => 'add', '?' => ['parent_id' => $noveltyType->id]],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Agregar subtipo']
            ) ?>
            <?php endif; ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link(
                '<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $noveltyType->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar']
            ) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $noveltyType->id],
                ['class' => 'btn-icon', 'escape' => false,
                 'confirm' => '¿Eliminar este tipo de novedad?', 'title' => 'Eliminar']
            ) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-list-check" aria-hidden="true"></i></div>
        <div class="es-title">Sin tipos de novedad</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

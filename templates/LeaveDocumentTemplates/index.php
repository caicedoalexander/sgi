<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\LeaveDocumentTemplate> $templates
 */
$this->assign('title', 'Plantillas de Documento');

$canEdit   = !empty($userPermissions['leave_document_templates']['can_edit']);
$canDelete = !empty($userPermissions['leave_document_templates']['can_delete']);
$gridCols  = '1fr 120px 140px 160px 120px 120px';
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Plantillas de Documento</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['leave_document_templates']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Plantilla',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="sgi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Nombre</span>
        <span>Estado</span>
        <span>Campos</span>
        <span>Tamaño</span>
        <span>Creada</span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($templates as $template): $rowCount++; ?>
    <div class="row-fact<?= $canEdit ? ' clickable-row' : '' ?>"
         style="grid-template-columns:<?= $gridCols ?>;"
         <?php if ($canEdit): ?>data-href="<?= $this->Url->build(['action' => 'edit', $template->id]) ?>"<?php endif; ?>
         role="row">
        <span>
            <div style="font-weight:600;color:var(--text-strong);"><?= h($template->name) ?></div>
            <?php if ($template->description): ?>
            <div style="font-size:var(--fs-body-sm);color:var(--text-muted);"><?= h(\Cake\Utility\Text::truncate($template->description, 60)) ?></div>
            <?php endif; ?>
        </span>
        <span>
            <?php if ($template->is_active): ?>
                <span class="pill pill-primary-soft">Activa</span>
            <?php else: ?>
                <span class="pill pill-secondary-soft">Inactiva</span>
            <?php endif; ?>
        </span>
        <span>
            <span class="pill pill-info-soft"><?= count($template->leave_template_fields) ?> campos</span>
        </span>
        <span class="mono" style="color:var(--text-muted);font-size:.8rem;">
            <?= number_format((float)$template->page_width, 1) ?> x <?= number_format((float)$template->page_height, 1) ?> mm
            <span class="pill pill-muted"><?= ($template->orientation ?? 'P') === 'L' ? 'Horizontal' : 'Vertical' ?></span>
        </span>
        <span class="mono" style="color:var(--text-muted);font-size:.8rem;">
            <?= $template->created?->format('d/m/Y') ?: '—' ?>
        </span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $template->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar']) ?>
            <?php endif; ?>
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'preview', $template->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Previsualizar', 'target' => '_blank']) ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $template->id],
                ['confirm' => '¿Eliminar esta plantilla?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-file-earmark-ruled" aria-hidden="true"></i></div>
        <div class="es-title">Sin plantillas de documento</div>
        <div class="es-msg">No hay plantillas registradas todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>

<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\LeaveDocumentTemplate> $templates
 */
$this->assign('title', 'Plantillas de Documento');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Plantillas de Documento</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Plantilla',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
    </div>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th style="width:120px;">Estado</th>
                    <th style="width:140px;">Campos</th>
                    <th style="width:140px;">Tamaño</th>
                    <th style="width:140px;">Creada</th>
                    <th style="width:160px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php $rowCount = 0; foreach ($templates as $template): $rowCount++; ?>
                <tr>
                    <td>
                        <div class="fw-medium"><?= h($template->name) ?></div>
                        <?php if ($template->description): ?>
                        <div class="text-muted" style="font-size:var(--fs-body-sm);"><?= h(\Cake\Utility\Text::truncate($template->description, 60)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($template->is_active): ?>
                            <span class="pill pill-primary-soft">Activa</span>
                        <?php else: ?>
                            <span class="pill pill-secondary-soft">Inactiva</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="pill pill-info-soft"><?= count($template->leave_template_fields) ?> campos</span>
                    </td>
                    <td>
                        <span style="font-size:.8rem;">
                            <?= number_format((float)$template->page_width, 1) ?> x <?= number_format((float)$template->page_height, 1) ?> mm
                            <span class="pill pill-muted"><?= ($template->orientation ?? 'P') === 'L' ? 'Horizontal' : 'Vertical' ?></span>
                        </span>
                    </td>
                    <td style="font-size:.8rem;">
                        <?= $template->created?->format('d/m/Y') ?: '—' ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <?= $this->Html->link(
                                '<i class="bi bi-pencil" aria-hidden="true"></i>',
                                ['action' => 'edit', $template->id],
                                ['class' => 'btn btn-sm btn-outline-dark', 'escape' => false, 'title' => 'Editar']
                            ) ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-eye" aria-hidden="true"></i>',
                                ['action' => 'preview', $template->id],
                                ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'title' => 'Previsualizar', 'target' => '_blank']
                            ) ?>
                            <?= $this->Form->postLink(
                                '<i class="bi bi-trash" aria-hidden="true"></i>',
                                ['action' => 'delete', $template->id],
                                ['class' => 'btn btn-sm btn-outline-danger', 'escape' => false,
                                 'confirm' => '¿Eliminar esta plantilla?']
                            ) ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ($rowCount === 0): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No hay plantillas registradas.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\NoveltyType> $noveltyTypes
 */
$this->assign('title', 'Tipos de Novedad');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Tipos de Novedad</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['novelty_types']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1"></i>Nuevo Tipo',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Subtipos</th>
                    <th>Plantilla</th>
                    <th style="width:160px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($noveltyTypes as $noveltyType): ?>
                <tr>
                    <td><strong><?= h($noveltyType->name) ?></strong></td>
                    <td>
                        <?php if (!empty($noveltyType->child_novelty_types)): ?>
                            <?php foreach ($noveltyType->child_novelty_types as $child): ?>
                                <span class="badge bg-light text-dark border me-1"><?= h($child->name) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($noveltyType->novelty_type_contract_templates)): ?>
                            <span class="text-muted">
                                <i class="bi bi-file-earmark-pdf me-1"></i><?= count($noveltyType->novelty_type_contract_templates) ?> asignación(es)
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <?php if (!empty($userPermissions['novelty_types']['can_create'])): ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-plus-circle"></i>',
                                ['action' => 'add', '?' => ['parent_id' => $noveltyType->id]],
                                ['class' => 'btn btn-sm btn-outline-success', 'escape' => false, 'title' => 'Agregar subtipo']
                            ) ?>
                            <?php endif; ?>
                            <?php if (!empty($userPermissions['novelty_types']['can_edit'])): ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-pencil"></i>',
                                ['action' => 'edit', $noveltyType->id],
                                ['class' => 'btn btn-sm btn-outline-dark', 'escape' => false]
                            ) ?>
                            <?php endif; ?>
                            <?php if (!empty($userPermissions['novelty_types']['can_delete'])): ?>
                            <?= $this->Form->postLink(
                                '<i class="bi bi-trash"></i>',
                                ['action' => 'delete', $noveltyType->id],
                                ['class' => 'btn btn-sm btn-outline-danger', 'escape' => false,
                                 'confirm' => '¿Eliminar este tipo de novedad?']
                            ) ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>

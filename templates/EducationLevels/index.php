<?php
$this->assign('title', 'Niveles Educativos');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Niveles Educativos</span>
    <div class="d-flex gap-2">
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'EducationLevels',
            'importable' => true,
            'canCreate' => !empty($userPermissions['education_levels']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['education_levels']['can_create'])): ?>
        <?= $this->Html->link('<i class="bi bi-plus-lg me-1"></i>Nuevo Nivel Educativo', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($educationLevels as $educationLevel): ?>
                <tr>
                    <td><?= $this->Number->format($educationLevel->id) ?></td>
                    <td><?= h($educationLevel->name) ?></td>
                    <td class="text-end">
                        <?= $this->Html->link('<i class="bi bi-eye"></i>', ['action' => 'view', $educationLevel->id], ['class' => 'btn btn-sm btn-outline-info', 'escape' => false, 'title' => 'Ver']) ?>
                        <?php if (!empty($userPermissions['education_levels']['can_edit'])): ?>
                        <?= $this->Html->link('<i class="bi bi-pencil"></i>', ['action' => 'edit', $educationLevel->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?php endif; ?>
                        <?php if (!empty($userPermissions['education_levels']['can_delete'])): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'delete', $educationLevel->id], ['confirm' => '¿Está seguro de eliminar?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>

<?= $this->element('excel_wizard/modals', [
    'module' => 'EducationLevels',
    'entityName' => 'Niveles Educativos',
    'downloadSlug' => 'niveles_educativos',
    'importable' => true,
]) ?>

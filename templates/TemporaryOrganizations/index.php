<?php
$this->assign('title', 'Organizaciones Temporales');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Organizaciones Temporales</span>
    <div class="d-flex gap-2">
        <?= $this->element('catalog_excel_buttons') ?>
        <?php if (!empty($userPermissions['temporary_organizations']['can_create'])): ?>
        <?= $this->Html->link('<i class="bi bi-plus-lg me-1"></i>Nueva Organización', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th><?= $this->Paginator->sort('nit', 'NIT') ?></th>
                    <th><?= $this->Paginator->sort('active', 'Estado') ?></th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($temporaryOrganizations as $org): ?>
                <tr>
                    <td><?= $this->Number->format($org->id) ?></td>
                    <td><?= h($org->name) ?></td>
                    <td><code><?= h($org->nit) ?></code></td>
                    <td>
                        <?php if ($org->active): ?>
                            <span class="badge bg-success">Activa</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactiva</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if (!empty($userPermissions['temporary_organizations']['can_edit'])): ?>
                        <?= $this->Html->link('<i class="bi bi-pencil"></i>', ['action' => 'edit', $org->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?php endif; ?>
                        <?php if (!empty($userPermissions['temporary_organizations']['can_delete'])): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'delete', $org->id], ['confirm' => '¿Está seguro de eliminar?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted"><?= $this->Paginator->counter('Mostrando {{start}}-{{end}} de {{count}}') ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?= $this->Paginator->first('«', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->prev('‹', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->numbers(['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->next('›', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->last('»', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
            </ul>
        </nav>
    </div>
</div>

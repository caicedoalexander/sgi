<?php
$this->assign('title', 'Carpetas por Defecto');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Carpetas por Defecto</span>
    <?= $this->Html->link('<i class="bi bi-plus-lg me-1"></i>Nueva Carpeta por Defecto', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
</div>

<?= $this->element('catalog_excel_buttons') ?>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th><?= $this->Paginator->sort('code', 'Código') ?></th>
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th><?= $this->Paginator->sort('sort_order', 'Orden') ?></th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($defaultFolders as $defaultFolder): ?>
                <tr>
                    <td><?= $this->Number->format($defaultFolder->id) ?></td>
                    <td><code><?= h($defaultFolder->code) ?></code></td>
                    <td><?= h($defaultFolder->name) ?></td>
                    <td><?= $this->Number->format($defaultFolder->sort_order) ?></td>
                    <td class="text-end">
                        <?= $this->Html->link('<i class="bi bi-eye"></i>', ['action' => 'view', $defaultFolder->id], ['class' => 'btn btn-sm btn-outline-info', 'escape' => false, 'title' => 'Ver']) ?>
                        <?= $this->Html->link('<i class="bi bi-pencil"></i>', ['action' => 'edit', $defaultFolder->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'delete', $defaultFolder->id], ['confirm' => '¿Está seguro de eliminar?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
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

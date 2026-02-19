<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\CostCenter> $costCenters
 */
$this->assign('title', 'Centros de Costos');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Centros de Costos</h1>
    <?= $this->Html->link('<i class="bi bi-plus-lg me-1"></i>Nuevo Centro', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th><?= $this->Paginator->sort('code', 'Código') ?></th>
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th><?= $this->Paginator->sort('created', 'Creado') ?></th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($costCenters as $costCenter): ?>
                <tr>
                    <td><?= $this->Number->format($costCenter->id) ?></td>
                    <td><code><?= h($costCenter->code ?? '-') ?></code></td>
                    <td><?= h($costCenter->name) ?></td>
                    <td><?= $costCenter->created?->format('d/m/Y H:i') ?></td>
                    <td class="text-end">
                        <?= $this->Html->link('<i class="bi bi-eye"></i>', ['action' => 'view', $costCenter->id], ['class' => 'btn btn-sm btn-outline-info', 'escape' => false, 'title' => 'Ver']) ?>
                        <?= $this->Html->link('<i class="bi bi-pencil"></i>', ['action' => 'edit', $costCenter->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'delete', $costCenter->id], ['confirm' => '¿Está seguro de eliminar este centro de costos?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
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

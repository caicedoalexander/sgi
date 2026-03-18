<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\OperationCenter> $operationCenters
 */
$this->assign('title', 'Centros de Operación');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Centros de Operación</span>
    <div class="d-flex gap-2">
        <?= $this->element('catalog_excel_buttons') ?>
        <?php if (!empty($userPermissions['operation_centers']['can_create'])): ?>
        <?= $this->Html->link('<i class="bi bi-plus-lg me-1"></i>Nuevo Centro', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
        <?php endif; ?>
    </div>
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
                <?php foreach ($operationCenters as $operationCenter): ?>
                <tr>
                    <td><?= $this->Number->format($operationCenter->id) ?></td>
                    <td><code><?= h($operationCenter->code ?? '-') ?></code></td>
                    <td><?= h($operationCenter->name) ?></td>
                    <td><?= $operationCenter->created?->format('d/m/Y H:i') ?></td>
                    <td class="text-end">
                        <?= $this->Html->link('<i class="bi bi-eye"></i>', ['action' => 'view', $operationCenter->id], ['class' => 'btn btn-sm btn-outline-info', 'escape' => false, 'title' => 'Ver']) ?>
                        <?php if (!empty($userPermissions['operation_centers']['can_edit'])): ?>
                        <?= $this->Html->link('<i class="bi bi-pencil"></i>', ['action' => 'edit', $operationCenter->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?php endif; ?>
                        <?php if (!empty($userPermissions['operation_centers']['can_delete'])): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'delete', $operationCenter->id], ['confirm' => '¿Está seguro de eliminar este centro de operación?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>

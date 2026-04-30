<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\ExpenseType> $expenseTypes
 */
$this->assign('title', 'Tipos de Gasto');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Tipos de Gasto</span>
    <?php if (!empty($userPermissions['expense_types']['can_create'])): ?>
    <?= $this->Html->link('<i class="bi bi-plus-lg me-1"></i>Nuevo Tipo', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
    <?php endif; ?>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th><?= $this->Paginator->sort('created', 'Creado') ?></th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenseTypes as $expenseType): ?>
                <tr>
                    <td><?= $this->Number->format($expenseType->id) ?></td>
                    <td><?= h($expenseType->name) ?></td>
                    <td><?= $expenseType->created?->format('d/m/Y H:i') ?></td>
                    <td class="text-end">
                        <?= $this->Html->link('<i class="bi bi-eye"></i>', ['action' => 'view', $expenseType->id], ['class' => 'btn btn-sm btn-outline-info', 'escape' => false, 'title' => 'Ver']) ?>
                        <?php if (!empty($userPermissions['expense_types']['can_edit'])): ?>
                        <?= $this->Html->link('<i class="bi bi-pencil"></i>', ['action' => 'edit', $expenseType->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?php endif; ?>
                        <?php if (!empty($userPermissions['expense_types']['can_delete'])): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'delete', $expenseType->id], ['confirm' => '¿Está seguro de eliminar este tipo de gasto?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>

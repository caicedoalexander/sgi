<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\ExpenseType $expenseType
 */
$this->assign('title', 'Tipo de Gasto: ' . $expenseType->name);
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Detalle del Tipo de Gasto</h5></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">ID</dt>
            <dd class="col-sm-9"><?= $this->Number->format($expenseType->id) ?></dd>
            <dt class="col-sm-3">Nombre</dt>
            <dd class="col-sm-9"><?= h($expenseType->name) ?></dd>
            <dt class="col-sm-3">Creado</dt>
            <dd class="col-sm-9"><?= $expenseType->created?->format('d/m/Y H:i') ?></dd>
            <dt class="col-sm-3">Modificado</dt>
            <dd class="col-sm-9"><?= $expenseType->modified?->format('d/m/Y H:i') ?></dd>
        </dl>
    </div>
    <div class="card-footer">
        <?= $this->Html->link('<i class="bi bi-pencil me-1"></i>Editar', ['action' => 'edit', $expenseType->id], ['class' => 'btn btn-warning btn-sm', 'escape' => false]) ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1"></i>Eliminar', ['action' => 'delete', $expenseType->id], ['confirm' => '¿Está seguro?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
    </div>
</div>

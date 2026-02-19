<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\ExpenseType $expenseType
 */
$this->assign('title', 'Editar Tipo de Gasto');
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Editar Tipo de Gasto</h5></div>
    <div class="card-body">
        <?= $this->Form->create($expenseType) ?>
        <div class="mb-3">
            <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
        </div>
        <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i>Actualizar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\ExpenseType $expenseType
 */
$this->assign('title', 'Nuevo Tipo de Gasto');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nuevo Tipo de Gasto</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<div class="card card-primary">
<div class="card-body">
        <?= $this->Form->create($expenseType) ?>
        <div class="mb-3">
            <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

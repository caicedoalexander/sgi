<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\OperationCenter $operationCenter
 */
$this->assign('title', 'Editar Centro de Operación');
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Editar Centro de Operación</h5></div>
    <div class="card-body">
        <?= $this->Form->create($operationCenter) ?>
        <div class="row">
            <div class="col-md-4 mb-3">
                <?= $this->Form->control('code', ['class' => 'form-control', 'label' => ['text' => 'Código', 'class' => 'form-label'], 'placeholder' => 'Ej: OC-001']) ?>
            </div>
            <div class="col-md-8 mb-3">
                <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
            </div>
        </div>
        <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i>Actualizar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

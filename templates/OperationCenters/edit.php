<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\OperationCenter $operationCenter
 */
$this->assign('title', 'Editar Centro de Operación');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Centro de Operación</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<div class="card card-primary">
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
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Actualizar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

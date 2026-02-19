<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Provider $provider
 */
$this->assign('title', 'Nuevo Proveedor');
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0">Nuevo Proveedor</h5></div>
    <div class="card-body">
        <?= $this->Form->create($provider) ?>
        <div class="row">
            <div class="col-md-4 mb-3">
                <?= $this->Form->control('nit', ['class' => 'form-control', 'label' => ['text' => 'NIT', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-8 mb-3">
                <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
            </div>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('active', ['class' => 'form-check-input', 'label' => ['text' => 'Activo', 'class' => 'form-check-label'], 'type' => 'checkbox', 'checked' => true]) ?>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

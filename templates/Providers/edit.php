<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Provider $provider
 */
$this->assign('title', 'Editar Proveedor');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Proveedor</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<div class="card card-primary">
<div class="card-body">
        <?= $this->Form->create($provider) ?>
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('document_type', [
                    'class' => 'form-select',
                    'label' => ['text' => 'Tipo de Documento', 'class' => 'form-label'],
                    'options' => ['NIT' => 'NIT', 'CC' => 'CC', 'Otro' => 'Otro'],
                ]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('document_number', ['class' => 'form-control', 'label' => ['text' => 'Número de Documento', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-6 mb-3">
                <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
            </div>
        </div>
        <div class="mb-3">
            <div class="form-check">
                <?= $this->Form->checkbox('active', ['class' => 'form-check-input']) ?>
                <?= $this->Form->label('active', 'Activo', ['class' => 'form-check-label']) ?>
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

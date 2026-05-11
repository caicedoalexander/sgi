<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\BankingEntity $bankingEntity
 */
$this->assign('title', 'Editar Entidad Bancaria');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Entidad Bancaria</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<div class="card card-primary">
    <div class="card-body">
        <?= $this->Form->create($bankingEntity) ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Código</label>
                <?= $this->Form->control('code', ['label' => false, 'class' => 'form-control']) ?>
            </div>
            <div class="col-md-8">
                <label class="form-label">Nombre</label>
                <?= $this->Form->control('name', ['label' => false, 'class' => 'form-control']) ?>
            </div>
            <div class="col-md-4">
                <div class="form-check mt-2">
                    <?= $this->Form->checkbox('active', ['class' => 'form-check-input', 'id' => 'active-check']) ?>
                    <label class="form-check-label" for="active-check">Activo</label>
                </div>
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

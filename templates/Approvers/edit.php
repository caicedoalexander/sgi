<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Approver $approver
 */
$this->assign('title', 'Editar Aprobador');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Aprobador</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<div class="card card-primary" style="max-width:600px;">
    <div class="card-body">
        <?= $this->Form->create($approver) ?>

        <div class="mb-3">
            <?= $this->Form->control('user_id', [
                'class' => 'form-select',
                'label' => ['text' => 'Usuario', 'class' => 'form-label'],
                'options' => $users,
                'empty' => '-- Seleccione usuario --',
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('operation_center_id', [
                'class' => 'form-select',
                'label' => ['text' => 'Centro de Operación (opcional)', 'class' => 'form-label'],
                'options' => $operationCenters,
                'empty' => '-- Todos los centros --',
            ]) ?>
        </div>
        <div class="mb-3">
            <div class="form-check">
                <?= $this->Form->checkbox('active', ['class' => 'form-check-input']) ?>
                <?= $this->Form->label('active', 'Activo', ['class' => 'form-check-label']) ?>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Actualizar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

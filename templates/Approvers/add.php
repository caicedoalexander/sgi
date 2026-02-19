<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Approver $approver
 */
$this->assign('title', 'Nuevo Aprobador');
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm" style="max-width:600px;">
    <div class="card-header"><h5 class="mb-0">Nuevo Aprobador</h5></div>
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
            <small class="text-muted">Si no selecciona, el aprobador aplica para todos los centros.</small>
        </div>
        <div class="mb-3">
            <div class="form-check">
                <?= $this->Form->control('active', [
                    'type' => 'checkbox',
                    'class' => 'form-check-input',
                    'label' => ['text' => 'Activo', 'class' => 'form-check-label'],
                    'checked' => true,
                ]) ?>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
        <?= $this->Form->end() ?>
    </div>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmployeeLeave $employeeLeave
 * @var array $employees
 * @var array $leaveTypes
 */
$this->assign('title', 'Nueva Solicitud de Permiso');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nueva Solicitud de Permiso</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body p-4">
        <?= $this->Form->create($employeeLeave) ?>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Empleado</label>
                <?= $this->Form->control('employee_id', [
                    'label' => false,
                    'options' => $employees,
                    'empty' => '-- Seleccione --',
                    'class' => 'form-select select2-enable',
                ]) ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tipo de Permiso</label>
                <?= $this->Form->control('leave_type_id', [
                    'label' => false,
                    'options' => $leaveTypes,
                    'empty' => '-- Seleccione --',
                    'class' => 'form-select',
                ]) ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Fecha Inicio</label>
                <input type="text" name="start_date" class="form-control flatpickr-date"
                       value="<?= h($employeeLeave->start_date?->format('Y-m-d') ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Fecha Fin</label>
                <input type="text" name="end_date" class="form-control flatpickr-date"
                       value="<?= h($employeeLeave->end_date?->format('Y-m-d') ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Observaciones</label>
                <?= $this->Form->control('observations', [
                    'label' => false,
                    'type' => 'textarea',
                    'rows' => 3,
                    'class' => 'form-control',
                ]) ?>
            </div>
        </div>

        <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Crear Solicitud
            </button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>

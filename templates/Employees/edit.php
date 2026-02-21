<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Employee $employee
 */
$this->assign('title', 'Editar Empleado: ' . $employee->full_name);
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'view', $employee->id], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<?= $this->Form->create($employee, ['type' => 'file']) ?>

<!-- Datos Personales -->
<div class="card shadow-sm mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-person me-2"></i>Datos Personales</h5></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('document_type', [
                    'class' => 'form-select',
                    'label' => ['text' => 'Tipo Documento', 'class' => 'form-label'],
                    'type' => 'select',
                    'options' => ['CC' => 'CC', 'CE' => 'CE', 'TI' => 'TI', 'PP' => 'Pasaporte', 'NIT' => 'NIT'],
                ]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('document_number', ['class' => 'form-control', 'label' => ['text' => 'Número Documento', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('first_name', ['class' => 'form-control', 'label' => ['text' => 'Nombres', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('last_name', ['class' => 'form-control', 'label' => ['text' => 'Apellidos', 'class' => 'form-label']]) ?>
            </div>
        </div>
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('birth_date', ['class' => 'form-control flatpickr-date', 'label' => ['text' => 'Fecha Nacimiento', 'class' => 'form-label'], 'type' => 'text']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('gender', [
                    'class' => 'form-select',
                    'label' => ['text' => 'Género', 'class' => 'form-label'],
                    'type' => 'select',
                    'options' => ['' => '-- Seleccione --', 'Masculino' => 'Masculino', 'Femenino' => 'Femenino', 'Otro' => 'Otro'],
                    'empty' => false,
                ]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('marital_status_id', ['class' => 'form-select', 'label' => ['text' => 'Estado Civil', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('education_level_id', ['class' => 'form-select', 'label' => ['text' => 'Nivel Educativo', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
            </div>
        </div>
    </div>
</div>

<!-- Contacto -->
<div class="card shadow-sm mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-telephone me-2"></i>Contacto</h5></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('email', ['class' => 'form-control', 'label' => ['text' => 'Correo Electrónico', 'class' => 'form-label'], 'type' => 'email']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('phone', ['class' => 'form-control', 'label' => ['text' => 'Teléfono', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('city', ['class' => 'form-control', 'label' => ['text' => 'Ciudad', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('address', ['class' => 'form-control', 'label' => ['text' => 'Dirección', 'class' => 'form-label']]) ?>
            </div>
        </div>
    </div>
</div>

<!-- Datos Laborales -->
<div class="card shadow-sm mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-briefcase me-2"></i>Datos Laborales</h5></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('employee_status_id', ['class' => 'form-select', 'label' => ['text' => 'Estado Empleado', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('position_id', ['class' => 'form-select', 'label' => ['text' => 'Cargo', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('supervisor_position_id', ['class' => 'form-select', 'label' => ['text' => 'Cargo Jefe Inmediato', 'class' => 'form-label'], 'empty' => '-- Seleccione --', 'options' => $positions]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('operation_center_id', ['class' => 'form-select', 'label' => ['text' => 'Centro de Operación', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
            </div>
        </div>
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('cost_center_id', ['class' => 'form-select', 'label' => ['text' => 'Centro de Costos', 'class' => 'form-label'], 'empty' => '-- Seleccione --']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('hire_date', ['class' => 'form-control flatpickr-date', 'label' => ['text' => 'Fecha Ingreso', 'class' => 'form-label'], 'type' => 'text']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('termination_date', ['class' => 'form-control flatpickr-date', 'label' => ['text' => 'Fecha Retiro', 'class' => 'form-label'], 'type' => 'text']) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('salary', ['class' => 'form-control currency-input', 'label' => ['text' => 'Salario', 'class' => 'form-label'], 'type' => 'text']) ?>
            </div>
        </div>
    </div>
</div>

<!-- Imagen de Perfil y Observaciones -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label">Imagen de Perfil</label>
                <?php if ($employee->profile_image): ?>
                    <div class="mb-2">
                        <img src="<?= $this->Url->build('/' . $employee->profile_image) ?>"
                             alt="Perfil" style="width:80px;height:80px;object-fit:cover;">
                    </div>
                <?php endif; ?>
                <input type="file" name="profile_image_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                <small class="text-muted">Max 2MB. JPEG, PNG, GIF o WebP</small>
            </div>
            <div class="col-md-9 mb-3">
                <?= $this->Form->control('notes', ['class' => 'form-control', 'label' => ['text' => 'Observaciones', 'class' => 'form-label'], 'rows' => 3]) ?>
            </div>
        </div>
    </div>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Actualizar</button>
<?= $this->Form->end() ?>

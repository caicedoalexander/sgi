<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Employee $employee
 * @var array $employeeStatuses
 * @var array $positions
 * @var array $temporaryOrganizations
 * @var string $mode 'add' | 'edit'
 */
use App\Constants\ContractTypeConstants;
use App\Constants\DocumentTypeConstants;
use App\Constants\GenderConstants;

$isEdit = ($mode ?? 'add') === 'edit';
$genderOptions = ['' => '-- Seleccione --'] + GenderConstants::LABELS;
?>
<!-- Datos Personales -->
<div class="card card-primary mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-person me-2" aria-hidden="true"></i>Datos Personales</h5></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('document_type', [
                    'class' => 'form-select',
                    'label' => ['text' => 'Tipo Documento', 'class' => 'form-label'],
                    'type' => 'select',
                    'options' => DocumentTypeConstants::LABELS,
                ]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('document_number', ['class' => 'form-control', 'label' => ['text' => 'Número Documento', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('first_name', ['class' => 'form-control', 'label' => ['text' => 'Nombres', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('last_name1', ['class' => 'form-control', 'label' => ['text' => 'Primer Apellido', 'class' => 'form-label']]) ?>
            </div>
        </div>
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('last_name2', ['class' => 'form-control', 'label' => ['text' => 'Segundo Apellido', 'class' => 'form-label']]) ?>
            </div>
        </div>
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('birth_date', [
                    'class' => 'form-control flatpickr-date',
                    'label' => ['text' => 'Fecha Nacimiento', 'class' => 'form-label'],
                    'type' => 'text',
                    'value' => $isEdit ? ($employee->birth_date?->format('Y-m-d') ?? '') : null,
                ]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('gender', [
                    'class' => 'form-select',
                    'label' => ['text' => 'Género', 'class' => 'form-label'],
                    'type' => 'select',
                    'options' => $genderOptions,
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
<div class="card card-primary mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-telephone me-2" aria-hidden="true"></i>Contacto</h5></div>
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
<div class="card card-primary mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-briefcase me-2" aria-hidden="true"></i>Datos Laborales</h5></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('status', ['type' => 'select', 'options' => $employeeStatuses, 'class' => 'form-select', 'label' => ['text' => 'Estado Empleado', 'class' => 'form-label']]) ?>
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
                <?= $this->Form->control('hire_date', [
                    'class' => 'form-control flatpickr-date',
                    'label' => ['text' => 'Fecha Ingreso', 'class' => 'form-label'],
                    'type' => 'text',
                    'value' => $isEdit ? ($employee->hire_date?->format('Y-m-d') ?? '') : null,
                ]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('termination_date', [
                    'class' => 'form-control flatpickr-date',
                    'label' => ['text' => 'Fecha Retiro', 'class' => 'form-label'],
                    'type' => 'text',
                    'value' => $isEdit ? ($employee->termination_date?->format('Y-m-d') ?? '') : null,
                ]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('salary', ['class' => 'form-control currency-input', 'label' => ['text' => 'Salario', 'class' => 'form-label'], 'type' => 'text']) ?>
            </div>
        </div>
        <div class="row">
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('contract_type', [
                    'class' => 'form-select',
                    'label' => ['text' => 'Tipo de Contrato', 'class' => 'form-label'],
                    'type' => 'select',
                    'options' => ContractTypeConstants::LABELS,
                    'empty' => '-- Seleccione --',
                    'id' => 'contract-type',
                ]) ?>
            </div>
            <div class="col-md-3 mb-3" id="org-temporal-wrapper" style="<?= !$employee->requiresTemporaryOrg() ? 'display:none' : '' ?>">
                <?= $this->Form->control('temporary_organization_id', ['class' => 'form-select', 'label' => ['text' => 'Organización Temporal', 'class' => 'form-label'], 'empty' => '-- Seleccione --', 'options' => $temporaryOrganizations]) ?>
            </div>
            <div class="col-md-3 mb-3">
                <?= $this->Form->control('vest_number', ['class' => 'form-control', 'label' => ['text' => 'N. Chaleco', 'class' => 'form-label'], 'maxlength' => 20]) ?>
            </div>
        </div>
    </div>
</div>
<?php $this->Html->scriptBlock(
    'window.SGI_OBRA_LABOR = ' . json_encode(ContractTypeConstants::OBRA_LABOR) . ';',
    ['block' => 'script'],
); ?>
<?= $this->Html->script('employees-form', ['block' => 'script']) ?>

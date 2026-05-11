<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\EmployeeNoveltyAddViewModel $viewModel
 */
use App\Constants\NoveltyConstants;

$novelty = $viewModel->novelty;
$employees = $viewModel->employees;
$noveltyTypes = $viewModel->noveltyTypes;
$preselectedEmployee = $viewModel->preselectedEmployee;
$approversList = $viewModel->approversList;

$this->assign('title', 'Nueva Novedad');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nueva Novedad</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body p-4">
        <?= $this->Form->create($novelty, ['type' => 'file']) ?>
        <input type="hidden" name="filing_date" value="<?= date('Y-m-d') ?>">

        <div class="row g-3">
            <!-- Employee select (single) -->
            <div class="col-md-6" id="employee-single-group">
                <label class="form-label">Empleado</label>
                <?= $this->Form->control('employee_id', [
                    'label' => false,
                    'options' => $employees,
                    'empty' => '-- Seleccione --',
                    'class' => 'form-select select2-enable',
                    'value' => $preselectedEmployee,
                ]) ?>
            </div>

            <!-- Employee multi-select (massive) -->
            <div class="col-md-6" id="employee-massive-group" style="display:none;">
                <label class="form-label">Empleados (Masiva)</label>
                <select name="massive_employee_ids[]" id="massive-employees" class="form-select select2-enable" multiple>
                    <?php foreach ($employees as $empId => $empName): ?>
                    <option value="<?= $empId ?>"><?= h($empName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Custom name input -->
            <div class="col-md-6" id="custom-name-group" style="display:none;">
                <label class="form-label">Nombre</label>
                <input type="text" name="custom_name" class="form-control" placeholder="Nombre libre">
            </div>

            <div class="col-md-6">
                <label class="form-label">Tipo de Novedad</label>
                <?= $this->Form->control('novelty_type_id', [
                    'label' => false,
                    'options' => $noveltyTypes,
                    'empty' => '-- Seleccione --',
                    'class' => 'form-select',
                    'id' => 'novelty-type-select',
                ]) ?>
            </div>

            <!-- Approver select (shown when type requires boss approval) -->
            <div class="col-md-6" id="approver-field" style="display:none;">
                <label class="form-label">Aprobador (Jefe Inmediato)</label>
                <?= $this->Form->control('approver_id', [
                    'label' => false,
                    'options' => $approversList ?? [],
                    'empty' => '— Seleccione aprobador —',
                    'class' => 'form-select select2',
                ]) ?>
            </div>

            <!-- Conditional fields -->
            <div class="col-md-4" id="permission-date-group">
                <label class="form-label">Fecha del Permiso</label>
                <input type="text" name="permission_date" class="form-control flatpickr-date"
                       value="<?= h($novelty->permission_date?->format('Y-m-d') ?? '') ?>">
            </div>
            <div class="col-md-4" id="schedule-type-group">
                <label class="form-label">Horario</label>
                <select name="schedule_type" id="schedule-type-select" class="form-select">
                    <option value="">-- Seleccione --</option>
                    <option value="<?= NoveltyConstants::SCHEDULE_DAYS ?>" <?= ($novelty->schedule_type ?? '') === NoveltyConstants::SCHEDULE_DAYS ? 'selected' : '' ?>>Por días</option>
                    <option value="<?= NoveltyConstants::SCHEDULE_HOURS ?>" <?= ($novelty->schedule_type ?? '') === NoveltyConstants::SCHEDULE_HOURS ? 'selected' : '' ?>>Por horas</option>
                </select>
            </div>
            <div class="col-md-4">
                <div class="form-check mt-4">
                    <input type="hidden" name="is_paid" value="0">
                    <input type="checkbox" name="is_paid" value="1" class="form-check-input"
                           id="paid-check" <?= !empty($novelty->is_paid) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="paid-check">Remunerado</label>
                </div>
            </div>

            <!-- Days fields -->
            <div class="col-md-4" id="start-date-group" style="display:none;">
                <label class="form-label">Fecha Inicio</label>
                <input type="text" name="start_date" class="form-control flatpickr-date"
                       value="<?= h($novelty->start_date?->format('Y-m-d') ?? '') ?>">
            </div>
            <div class="col-md-4" id="end-date-group" style="display:none;">
                <label class="form-label">Fecha Fin</label>
                <input type="text" name="end_date" class="form-control flatpickr-date"
                       value="<?= h($novelty->end_date?->format('Y-m-d') ?? '') ?>">
            </div>

            <!-- Hours fields -->
            <div class="col-md-4" id="start-time-group" style="display:none;">
                <label class="form-label">Hora Salida</label>
                <input type="time" name="start_time" class="form-control"
                       value="<?= h($novelty->start_time ?? '') ?>">
            </div>
            <div class="col-md-4" id="end-time-group" style="display:none;">
                <label class="form-label">Hora Entrada</label>
                <input type="time" name="end_time" class="form-control"
                       value="<?= h($novelty->end_time ?? '') ?>">
            </div>

            <div class="col-12">
                <label class="form-label">Motivo</label>
                <?= $this->Form->control('reason', [
                    'label' => false,
                    'type' => 'textarea',
                    'rows' => 3,
                    'class' => 'form-control',
                ]) ?>
            </div>

            <!-- Firma del Funcionario (shown when type requires employee signature at creation) -->
            <div class="col-12" id="signature-field" style="display:none;">
                <label class="form-label">Firma del Funcionario <span class="text-muted fw-normal" style="font-size:.78rem;">(Opcional)</span></label>
                <div class="d-flex gap-3 align-items-start mb-2">
                    <div>
                        <input type="file" name="signature_file" id="signature-file" class="form-control form-control-sm"
                               accept="image/png,image/jpeg" style="max-width:300px;">
                        <div class="form-text">Suba una imagen o haga clic en el recuadro para dibujar su firma</div>
                    </div>
                </div>
                <div class="sgi-signature-pad" data-target="#signature-base64"
                     data-signer-label="Firma del Funcionario"
                     style="width:320px;height:120px;"></div>
                <input type="hidden" name="signature_base64" id="signature-base64">
            </div>
        </div>

        <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary" id="btn-submit">
                <i class="bi bi-save me-1" aria-hidden="true"></i>Registrar Novedad
            </button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>

<script>
(function() {
    // Toggle fields based on schedule type
    var scheduleSelect = document.getElementById('schedule-type-select');
    var startDateGroup = document.getElementById('start-date-group');
    var endDateGroup = document.getElementById('end-date-group');
    var startTimeGroup = document.getElementById('start-time-group');
    var endTimeGroup = document.getElementById('end-time-group');

    function toggleScheduleFields() {
        var val = scheduleSelect.value;
        startDateGroup.style.display = val === 'days' ? '' : 'none';
        endDateGroup.style.display = val === 'days' ? '' : 'none';
        startTimeGroup.style.display = val === 'hours' ? '' : 'none';
        endTimeGroup.style.display = val === 'hours' ? '' : 'none';
    }
    scheduleSelect.addEventListener('change', toggleScheduleFields);
    toggleScheduleFields();

    // Dynamic type flags
    var typeSelect = document.getElementById('novelty-type-select');
    var singleGroup = document.getElementById('employee-single-group');
    var massiveGroup = document.getElementById('employee-massive-group');
    var customNameGroup = document.getElementById('custom-name-group');
    var permissionDateGroup = document.getElementById('permission-date-group');
    var scheduleTypeGroup = document.getElementById('schedule-type-group');

    typeSelect.addEventListener('change', function() {
        var typeId = this.value;
        if (!typeId) return;

        fetch('/novelty-types/get-flags/' + typeId)
            .then(function(r) { return r.json(); })
            .then(function(flags) {
                // Employee mode
                singleGroup.style.display = (!flags.uses_custom_name && !flags.is_massive) ? '' : 'none';
                massiveGroup.style.display = flags.is_massive ? '' : 'none';
                customNameGroup.style.display = (flags.uses_custom_name && !flags.is_massive) ? '' : 'none';

                // Conditional fields
                permissionDateGroup.style.display = flags.show_permission_date ? '' : 'none';
                scheduleTypeGroup.style.display = flags.show_schedule_type ? '' : 'none';

                if (!flags.show_schedule_type) {
                    startDateGroup.style.display = flags.show_start_date ? '' : 'none';
                    endDateGroup.style.display = flags.show_end_date ? '' : 'none';
                    startTimeGroup.style.display = 'none';
                    endTimeGroup.style.display = 'none';
                } else {
                    toggleScheduleFields();
                }

                // Show/hide approver field
                var approverField = document.getElementById('approver-field');
                if (approverField) {
                    approverField.style.display = flags.requires_boss_approval ? '' : 'none';
                }

                // Show/hide employee signature field
                var sigField = document.getElementById('signature-field');
                if (sigField) {
                    sigField.style.display = flags.requires_employee_signature_creation ? '' : 'none';
                }

                // Re-init Select2 for massive if shown
                if (flags.is_massive && typeof jQuery !== 'undefined') {
                    jQuery('#massive-employees').select2({
                        placeholder: 'Seleccione empleados...',
                        allowClear: true,
                        width: '100%'
                    });
                }
            });
    });

})();
</script>
<?= $this->Html->script('sgi-signature') ?>
<?= $this->Html->script('sgi-epadlink') ?>

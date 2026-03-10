<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmployeeNovelty $novelty
 * @var array $employees
 * @var array $noveltyTypes
 * @var string|null $preselectedEmployee
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Nueva Novedad');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nueva Novedad</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body p-4">
        <?= $this->Form->create($novelty, ['type' => 'file']) ?>
        <input type="hidden" name="filing_date" value="<?= date('Y-m-d') ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Empleado</label>
                <?= $this->Form->control('employee_id', [
                    'label' => false,
                    'options' => $employees,
                    'empty' => '-- Seleccione --',
                    'class' => 'form-select select2-enable',
                    'value' => $preselectedEmployee,
                ]) ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tipo de Novedad</label>
                <?= $this->Form->control('novelty_type_id', [
                    'label' => false,
                    'options' => $noveltyTypes,
                    'empty' => '-- Seleccione --',
                    'class' => 'form-select',
                ]) ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">Fecha del Permiso</label>
                <input type="text" name="permission_date" class="form-control flatpickr-date"
                       value="<?= h($novelty->permission_date?->format('Y-m-d') ?? '') ?>">
            </div>
            <div class="col-md-4">
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

            <!-- Firma del Funcionario -->
            <div class="col-12">
                <label class="form-label">Firma del Funcionario</label>
                <div class="d-flex gap-3 align-items-start">
                    <div>
                        <input type="file" name="signature_file" id="signature-file" class="form-control form-control-sm"
                               accept="image/png,image/jpeg" style="max-width:300px;">
                        <div class="form-text">O dibuje su firma abajo</div>
                    </div>
                </div>
                <div class="mt-2" style="border:1px solid var(--border-color);display:inline-block;">
                    <canvas id="signature-canvas" width="400" height="150" style="cursor:crosshair;display:block;"></canvas>
                </div>
                <input type="hidden" name="signature_base64" id="signature-base64">
                <div class="mt-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="signature-clear">
                        <i class="bi bi-eraser me-1"></i>Limpiar Firma
                    </button>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary" id="btn-submit">
                <i class="bi bi-save me-1"></i>Registrar Novedad
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

    // Signature canvas
    var canvas = document.getElementById('signature-canvas');
    var ctx = canvas.getContext('2d');
    var drawing = false;
    var hasDrawn = false;

    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';

    function getPos(e) {
        var rect = canvas.getBoundingClientRect();
        var clientX, clientY;
        if (e.touches && e.touches.length > 0) {
            clientX = e.touches[0].clientX;
            clientY = e.touches[0].clientY;
        } else {
            clientX = e.clientX;
            clientY = e.clientY;
        }
        return { x: clientX - rect.left, y: clientY - rect.top };
    }

    canvas.addEventListener('mousedown', function(e) { drawing = true; var pos = getPos(e); ctx.beginPath(); ctx.moveTo(pos.x, pos.y); });
    canvas.addEventListener('mousemove', function(e) { if (!drawing) return; hasDrawn = true; var pos = getPos(e); ctx.lineTo(pos.x, pos.y); ctx.stroke(); });
    canvas.addEventListener('mouseup', function() { drawing = false; });
    canvas.addEventListener('mouseleave', function() { drawing = false; });

    canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; var pos = getPos(e); ctx.beginPath(); ctx.moveTo(pos.x, pos.y); });
    canvas.addEventListener('touchmove', function(e) { e.preventDefault(); if (!drawing) return; hasDrawn = true; var pos = getPos(e); ctx.lineTo(pos.x, pos.y); ctx.stroke(); });
    canvas.addEventListener('touchend', function() { drawing = false; });

    document.getElementById('signature-clear').addEventListener('click', function() {
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        hasDrawn = false;
        document.getElementById('signature-base64').value = '';
    });

    document.getElementById('btn-submit').closest('form').addEventListener('submit', function() {
        if (hasDrawn) {
            document.getElementById('signature-base64').value = canvas.toDataURL('image/png');
        }
    });
})();
</script>

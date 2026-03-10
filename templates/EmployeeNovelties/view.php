<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmployeeNovelty $novelty
 * @var bool $canApprove
 * @var bool $hasActiveTemplate
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Detalle de Novedad');

$statusBadges = [
    'pendiente' => 'bg-warning text-dark',
    'aprobado' => 'bg-success',
    'rechazado' => 'bg-danger',
];
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Detalle de Novedad</span>
    <div class="d-flex gap-2">
        <?php if (!empty($hasActiveTemplate)): ?>
        <?= $this->Html->link(
            '<i class="bi bi-file-earmark-pdf me-1"></i>Exportar PDF',
            ['action' => 'exportPdf', $novelty->id],
            ['class' => 'btn btn-outline-danger btn-sm', 'escape' => false, 'target' => '_blank']
        ) ?>
        <?php endif; ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;">
                    <?= h($novelty->employee->full_name ?? '') ?>
                </div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                    <?= h($novelty->novelty_type->name ?? '') ?>
                </div>
            </div>
        </div>
        <span class="badge <?= $statusBadges[$novelty->status] ?? 'bg-secondary' ?>">
            <?= ucfirst(h($novelty->status)) ?>
        </span>
    </div>

    <div class="row g-0" style="border-top:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Información de la Novedad</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Empleado</span>
                <span class="sgi-data-value"><?= h($novelty->employee->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo</span>
                <span class="sgi-data-value"><?= h($novelty->novelty_type->name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha del Permiso</span>
                <span class="sgi-data-value"><?= $novelty->permission_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Horario</span>
                <span class="sgi-data-value"><?= $scheduleLabels[$novelty->schedule_type] ?? h($novelty->schedule_type) ?></span>
            </div>
            <?php if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_DAYS): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Inicio</span>
                <span class="sgi-data-value"><?= $novelty->start_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Fin</span>
                <span class="sgi-data-value"><?= $novelty->end_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_HOURS): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Hora Salida</span>
                <span class="sgi-data-value"><?= h($novelty->start_time) ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Hora Entrada</span>
                <span class="sgi-data-value"><?= h($novelty->end_time) ?: '—' ?></span>
            </div>
            <?php endif; ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Remunerado</span>
                <span class="sgi-data-value">
                    <?php if ($novelty->is_paid): ?>
                        <span class="badge bg-success">Sí</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">No</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($novelty->reason): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Motivo</span>
                <span class="sgi-data-value"><?= nl2br(h($novelty->reason)) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-md-6">
            <div class="sgi-section-title">Gestión</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Registrado por</span>
                <span class="sgi-data-value"><?= h($novelty->registered_by_user->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Aprobado por</span>
                <span class="sgi-data-value"><?= h($novelty->approved_by_user->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Aprobación</span>
                <span class="sgi-data-value"><?= $novelty->approved_at ? $novelty->approved_at->format('d/m/Y H:i') : '—' ?></span>
            </div>
            <?php if ($novelty->filing_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Diligenciamiento</span>
                <span class="sgi-data-value"><?= $novelty->filing_date->format('d/m/Y') ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Firmas -->
    <?php if ($novelty->employee_signature): ?>
    <div style="border-top:1px solid var(--border-color);">
        <div class="sgi-section-title">Firma del Funcionario</div>
        <div style="padding:.25rem 1.25rem .875rem;">
            <img src="<?= $this->Url->build('/' . $novelty->employee_signature) ?>" alt="Firma Funcionario"
                 style="max-width:400px;max-height:150px;border:1px solid var(--border-color);">
        </div>
    </div>
    <?php endif; ?>

    <?php if ($novelty->coordinator_signature): ?>
    <div style="border-top:1px solid var(--border-color);">
        <div class="sgi-section-title">Firma del Coordinador</div>
        <div style="padding:.25rem 1.25rem .875rem;">
            <img src="<?= $this->Url->build('/' . $novelty->coordinator_signature) ?>" alt="Firma Coordinador"
                 style="max-width:400px;max-height:150px;border:1px solid var(--border-color);">
        </div>
    </div>
    <?php endif; ?>

    <?php if ($novelty->observations): ?>
    <div style="border-top:1px solid var(--border-color);">
        <div class="sgi-section-title">Observaciones</div>
        <div style="padding:.25rem 1.25rem .875rem;font-size:.875rem;color:#555;line-height:1.65;">
            <?= nl2br(h($novelty->observations)) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canApprove): ?>
    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal">
                <i class="bi bi-check-lg me-1"></i>Aprobar
            </button>
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="bi bi-x-lg me-1"></i>Rechazar
            </button>
        </div>
    </div>

    <!-- Approve modal (with coordinator signature) -->
    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <?= $this->Form->create(null, ['url' => ['action' => 'approve', $novelty->id], 'type' => 'file']) ?>
                <div class="modal-header">
                    <h5 class="modal-title">Aprobar Novedad</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Aprobar esta novedad para <strong><?= h($novelty->employee->full_name ?? '') ?></strong>?</p>

                    <label class="form-label">Firma del Coordinador (opcional)</label>
                    <div>
                        <input type="file" name="coordinator_signature_file" class="form-control form-control-sm mb-2"
                               accept="image/png,image/jpeg" style="max-width:300px;">
                        <div class="form-text">O dibuje la firma abajo</div>
                    </div>
                    <div class="mt-2" style="border:1px solid var(--border-color);display:inline-block;">
                        <canvas id="coord-signature-canvas" width="400" height="150" style="cursor:crosshair;display:block;"></canvas>
                    </div>
                    <input type="hidden" name="coordinator_signature_base64" id="coord-signature-base64">
                    <div class="mt-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="coord-signature-clear">
                            <i class="bi bi-eraser me-1"></i>Limpiar
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success" id="btn-approve">Aprobar</button>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>

    <!-- Reject modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <?= $this->Form->create(null, ['url' => ['action' => 'reject', $novelty->id]]) ?>
                <div class="modal-header">
                    <h5 class="modal-title">Rechazar Novedad</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Motivo del rechazo</label>
                    <textarea name="observations" class="form-control" rows="3"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Rechazar</button>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var canvas = document.getElementById('coord-signature-canvas');
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
            var cx = e.touches ? e.touches[0].clientX : e.clientX;
            var cy = e.touches ? e.touches[0].clientY : e.clientY;
            return { x: cx - rect.left, y: cy - rect.top };
        }

        canvas.addEventListener('mousedown', function(e) { drawing = true; var p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
        canvas.addEventListener('mousemove', function(e) { if (!drawing) return; hasDrawn = true; var p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
        canvas.addEventListener('mouseup', function() { drawing = false; });
        canvas.addEventListener('mouseleave', function() { drawing = false; });
        canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; var p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
        canvas.addEventListener('touchmove', function(e) { e.preventDefault(); if (!drawing) return; hasDrawn = true; var p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
        canvas.addEventListener('touchend', function() { drawing = false; });

        document.getElementById('coord-signature-clear').addEventListener('click', function() {
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); hasDrawn = false;
            document.getElementById('coord-signature-base64').value = '';
        });

        document.getElementById('btn-approve').closest('form').addEventListener('submit', function() {
            if (hasDrawn) { document.getElementById('coord-signature-base64').value = canvas.toDataURL('image/png'); }
        });
    })();
    </script>
    <?php endif; ?>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\NoveltyLiquidationDoc $doc
 * @var array $groupErrors
 * @var array $effectiveStatuses
 * @var array $documentsByStatus
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Documento de Liquidación: ' . h($doc->liquidation_number));

$statusLabels = NoveltyConstants::STATUS_LABELS;
$statusIcons = NoveltyConstants::STATUS_ICONS;
$periodLabels = NoveltyConstants::PERIOD_LABELS;
$signerLabels = NoveltyConstants::SIGNER_LABELS;
$paymentLabels = NoveltyConstants::PAYMENT_LABELS;
$isRejected = $doc->pipeline_status === NoveltyConstants::STATUS_RECHAZADA;
$isPaid = $doc->pipeline_status === NoveltyConstants::STATUS_PAGADA;
$isFinal = $isRejected || $isPaid;
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Liquidación: <?= h($doc->liquidation_number) ?></span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<!-- Pipeline Progress -->
<?= $this->element('pipeline_progress', [
    'pipelineStatuses' => $effectiveStatuses,
    'pipelineLabels' => $statusLabels,
    'currentStatus' => $doc->pipeline_status,
    'isRejected' => $isRejected,
    'statusIcons' => $statusIcons,
]) ?>

<!-- Header Card -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi bi-file-earmark-text"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;"><?= h($doc->liquidation_number) ?></div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;"><?= $periodLabels[$doc->period] ?? h($doc->period) ?></div>
            </div>
        </div>
        <span class="badge bg-<?= $isRejected ? 'danger' : 'primary' ?>">
            <?= $statusLabels[$doc->pipeline_status] ?? ucfirst(h($doc->pipeline_status)) ?>
        </span>
    </div>

    <div class="row g-0" style="border-top:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Información del Documento</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">No. Liquidación</span>
                <span class="sgi-data-value"><?= h($doc->liquidation_number) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Período</span>
                <span class="sgi-data-value"><?= $periodLabels[$doc->period] ?? h($doc->period) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Documento</span>
                <span class="sgi-data-value"><?= $doc->document_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Elaborado por</span>
                <span class="sgi-data-value"><?= h($doc->performed_by_user->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Creado por</span>
                <span class="sgi-data-value"><?= h($doc->created_by_user->full_name ?? '—') ?></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="sgi-section-title">Novedades Asociadas (<?= count($doc->employee_novelties) ?>)</div>
            <?php if (!empty($doc->employee_novelties)): ?>
            <div style="padding:0 1.25rem .875rem;max-height:300px;overflow-y:auto;">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>Tipo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($doc->employee_novelties as $novelty): ?>
                        <tr>
                            <td>
                                <?= $this->Html->link(
                                    h($novelty->custom_name ?? $novelty->employee->full_name ?? '—'),
                                    ['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id],
                                    ['class' => 'text-decoration-none']
                                ) ?>
                            </td>
                            <td style="font-size:.8125rem;"><?= h($novelty->novelty_type->name ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="padding:.25rem 1.25rem .875rem;">
                <p class="text-muted small mb-0">No hay novedades asociadas.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- GDP Stage: Passes for payment -->
    <?php if ($doc->pipeline_status === NoveltyConstants::STATUS_GDP && !$isFinal): ?>
    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]) ?>
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Pasa para Pago</label>
                <select name="passes_for_payment" class="form-select" required>
                    <option value="">-- Seleccione --</option>
                    <option value="1" <?= $doc->passes_for_payment === true ? 'selected' : '' ?>>Sí</option>
                    <option value="0" <?= $doc->passes_for_payment === false ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-arrow-right me-1"></i>Avanzar Grupo
                </button>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
    <?php endif; ?>

    <!-- Treasury Stage: Payment status/date -->
    <?php if ($doc->pipeline_status === NoveltyConstants::STATUS_TESORERIA && !$isFinal): ?>
    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]) ?>
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Estado de Pago</label>
                <select name="payment_status" class="form-select" required>
                    <option value="">-- Seleccione --</option>
                    <?php foreach ($paymentLabels as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($doc->payment_status ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Fecha de Pago</label>
                <input type="text" name="payment_date" class="form-control flatpickr-date"
                       value="<?= h($doc->payment_date?->format('Y-m-d') ?? '') ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-arrow-right me-1"></i>Avanzar Grupo
                </button>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
    <?php endif; ?>

    <!-- Generic advance for contabilidad stage -->
    <?php if ($doc->pipeline_status === NoveltyConstants::STATUS_CONTABILIDAD && !$isFinal): ?>
    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id], 'class' => 'd-inline']) ?>
        <button type="submit" class="btn btn-success">
            <i class="bi bi-arrow-right me-1"></i>Avanzar Grupo a <?= $statusLabels[NoveltyConstants::STATUS_FIRMAS_APROBACION] ?? '' ?>
        </button>
        <?= $this->Form->end() ?>
    </div>
    <?php endif; ?>

    <!-- Firmas Stage: Advance button (only if no errors) -->
    <?php if ($doc->pipeline_status === NoveltyConstants::STATUS_FIRMAS_APROBACION && !$isFinal): ?>
    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <?php if (empty($groupErrors)): ?>
        <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id], 'class' => 'd-inline']) ?>
        <button type="submit" class="btn btn-success">
            <i class="bi bi-arrow-right me-1"></i>Avanzar Grupo
        </button>
        <?= $this->Form->end() ?>
        <?php else: ?>
        <div class="text-warning small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= implode('<br>', array_map('h', $groupErrors)) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Signatures Section (visible in firmas_aprobacion stage) -->
<?php if ($doc->pipeline_status === NoveltyConstants::STATUS_FIRMAS_APROBACION || !empty($doc->novelty_liquidation_signatures)): ?>
<div class="card card-primary mb-4">
    <div class="card-header">
        <strong>Firmas</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($doc->novelty_liquidation_signatures as $sig): ?>
            <div class="col-md-6 col-lg-3">
                <div class="border rounded p-3 text-center h-100">
                    <div class="fw-bold small mb-2"><?= $signerLabels[$sig->signer_type] ?? h($sig->signer_type) ?></div>
                    <?php if ($sig->signature_path): ?>
                        <img src="<?= $this->Url->build('/' . $sig->signature_path) ?>" alt="Firma"
                             style="max-width:100%;max-height:100px;border:1px solid var(--border-color);">
                        <div class="mt-1 small text-muted">
                            <?= h($sig->signed_by_user->full_name ?? '') ?>
                            <?php if ($sig->approved_at): ?>
                            <br><?= $sig->approved_at->format('d/m/Y H:i') ?>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-success mt-1">Firmado</span>
                    <?php else: ?>
                        <?php if ($doc->pipeline_status === NoveltyConstants::STATUS_FIRMAS_APROBACION): ?>
                        <div style="border:1px solid var(--border-color);display:inline-block;" class="mb-2">
                            <canvas class="sig-canvas" data-signer="<?= h($sig->signer_type) ?>" width="250" height="100"
                                    style="cursor:crosshair;display:block;"></canvas>
                        </div>
                        <?= $this->Form->create(null, ['url' => ['action' => 'addSignature', $doc->id], 'class' => 'sig-form']) ?>
                        <input type="hidden" name="signer_type" value="<?= h($sig->signer_type) ?>">
                        <input type="hidden" name="signature_base64" class="sig-base64">
                        <div class="d-flex gap-1 justify-content-center">
                            <button type="button" class="btn btn-sm btn-outline-secondary sig-clear">Limpiar</button>
                            <button type="submit" class="btn btn-sm btn-primary sig-save">Firmar</button>
                        </div>
                        <?= $this->Form->end() ?>
                        <?php else: ?>
                        <span class="badge bg-secondary mt-2">Pendiente</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Documents Section -->
<div class="card card-primary mb-4">
    <div class="card-header">
        <strong>Documentos</strong>
    </div>
    <div class="card-body">
        <?php if (!empty($documentsByStatus)): ?>
            <?php foreach ($documentsByStatus as $stage => $docs_list): ?>
            <div class="mb-3">
                <span class="sgi-section-label"><?= $statusLabels[$stage] ?? ucfirst($stage) ?></span>
                <ul class="list-group list-group-flush mt-1">
                    <?php foreach ($docs_list as $docFile): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <div>
                            <a href="<?= $this->Url->build('/' . $docFile->file_path) ?>" target="_blank" class="text-decoration-none">
                                <i class="bi bi-file-earmark me-1"></i><?= h($docFile->file_name) ?>
                            </a>
                            <small class="text-muted ms-2"><?= h($docFile->uploaded_by_user->full_name ?? '') ?></small>
                        </div>
                        <?php if ($docFile->pipeline_status === $doc->pipeline_status): ?>
                        <?= $this->Form->postLink(
                            '<i class="bi bi-trash"></i>',
                            ['action' => 'deleteDocument', $doc->id, $docFile->id],
                            ['escape' => false, 'class' => 'btn btn-sm btn-outline-danger', 'confirm' => 'Eliminar este documento?']
                        ) ?>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-muted small mb-0">No hay documentos adjuntos.</p>
        <?php endif; ?>

        <?php if (!$isFinal): ?>
        <div class="mt-3 pt-3" style="border-top:1px solid var(--border-color);">
            <?= $this->Form->create(null, ['url' => ['action' => 'uploadDocument', $doc->id], 'type' => 'file', 'class' => 'd-flex gap-2 align-items-end']) ?>
            <div>
                <label class="form-label small">Adjuntar documento</label>
                <input type="file" name="document" class="form-control form-control-sm" required style="max-width:300px;">
            </div>
            <button type="submit" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-upload me-1"></i>Subir
            </button>
            <?= $this->Form->end() ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Observations Chat -->
<div class="card card-primary mb-4">
    <div class="card-header">
        <strong>Observaciones</strong>
    </div>
    <div class="card-body">
        <?php if (!empty($doc->novelty_observations)): ?>
        <div style="max-height:400px;overflow-y:auto;" class="mb-3">
            <?php foreach ($doc->novelty_observations as $obs): ?>
            <div class="d-flex gap-2 mb-3">
                <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:32px;height:32px;background:#e9ecef;border-radius:50%;font-size:.75rem;font-weight:600;">
                    <?= strtoupper(substr($obs->user->full_name ?? '?', 0, 1)) ?>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between">
                        <strong style="font-size:.8125rem;"><?= h($obs->user->full_name ?? '') ?></strong>
                        <small class="text-muted"><?= $obs->created?->format('d/m/Y H:i') ?></small>
                    </div>
                    <div style="font-size:.875rem;color:#333;margin-top:.15rem;"><?= nl2br(h($obs->message)) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted small mb-3">No hay observaciones.</p>
        <?php endif; ?>

        <?php if (!$isFinal): ?>
        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $doc->id], 'class' => 'd-flex gap-2']) ?>
        <input type="text" name="message" class="form-control form-control-sm" placeholder="Escribir observación..." required>
        <button type="submit" class="btn btn-sm btn-primary flex-shrink-0">
            <i class="bi bi-send me-1"></i>Enviar
        </button>
        <?= $this->Form->end() ?>
        <?php endif; ?>
    </div>
</div>

<!-- Signature canvas JS -->
<script>
(function() {
    document.querySelectorAll('.sig-canvas').forEach(function(canvas) {
        var ctx = canvas.getContext('2d');
        var drawing = false;
        var hasDrawn = false;
        var form = canvas.closest('.col-md-6, .col-lg-3').querySelector('.sig-form');
        var base64Input = form ? form.querySelector('.sig-base64') : null;

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

        var clearBtn = canvas.closest('.col-md-6, .col-lg-3').querySelector('.sig-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
                hasDrawn = false;
                if (base64Input) base64Input.value = '';
            });
        }

        if (form) {
            form.addEventListener('submit', function() {
                if (hasDrawn && base64Input) {
                    base64Input.value = canvas.toDataURL('image/png');
                }
            });
        }
    });
})();
</script>

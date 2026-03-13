<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmployeeNovelty $novelty
 * @var array $effectiveStatuses
 * @var string|null $nextStatus
 * @var array $transitionErrors
 * @var bool $canAdvance
 * @var array $documentsByStatus
 * @var bool $hasActiveTemplate
 * @var array $liquidationDocs
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Detalle de Novedad');

$statusLabels = NoveltyConstants::STATUS_LABELS;
$statusIcons = NoveltyConstants::STATUS_ICONS;
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
$isRejected = $novelty->isRejected();
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

<!-- Pipeline Progress -->
<?= $this->element('pipeline_progress', [
    'pipelineStatuses' => $effectiveStatuses,
    'pipelineLabels' => $statusLabels,
    'currentStatus' => $novelty->pipeline_status,
    'isRejected' => $isRejected,
    'statusIcons' => $statusIcons,
]) ?>

<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;">
                    <?= h($novelty->custom_name ?? $novelty->employee->full_name ?? '—') ?>
                </div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                    <?= h($novelty->novelty_type->name ?? '') ?>
                </div>
            </div>
        </div>
        <span class="badge bg-<?= $isRejected ? 'danger' : 'primary' ?>">
            <?= $statusLabels[$novelty->pipeline_status] ?? ucfirst(h($novelty->pipeline_status)) ?>
        </span>
    </div>

    <div class="row g-0" style="border-top:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Información de la Novedad</div>
            <?php if ($novelty->employee): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Empleado</span>
                <span class="sgi-data-value"><?= h($novelty->employee->full_name ?? '—') ?></span>
            </div>
            <?php elseif ($novelty->custom_name): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Nombre</span>
                <span class="sgi-data-value"><?= h($novelty->custom_name) ?></span>
            </div>
            <?php endif; ?>

            <!-- Massive employees -->
            <?php if (!empty($novelty->novelty_massive_employees)): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Empleados (Masiva)</span>
                <span class="sgi-data-value">
                    <?php foreach ($novelty->novelty_massive_employees as $me): ?>
                        <span class="badge bg-light text-dark me-1 mb-1"><?= h($me->employee->full_name ?? '—') ?></span>
                    <?php endforeach; ?>
                </span>
            </div>
            <?php endif; ?>

            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo</span>
                <span class="sgi-data-value"><?= h($novelty->novelty_type->name ?? '—') ?></span>
            </div>
            <?php if ($novelty->permission_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha del Permiso</span>
                <span class="sgi-data-value"><?= $novelty->permission_date->format('d/m/Y') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Horario</span>
                <span class="sgi-data-value"><?= $scheduleLabels[$novelty->schedule_type] ?? h($novelty->schedule_type) ?></span>
            </div>
            <?php endif; ?>
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
                    <span class="badge bg-<?= $novelty->is_paid ? 'success' : 'secondary' ?>"><?= $novelty->is_paid ? 'Sí' : 'No' ?></span>
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
            <?php if ($novelty->rrhh_by_user): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Procesado RRHH por</span>
                <span class="sgi-data-value"><?= h($novelty->rrhh_by_user->full_name ?? '—') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->passes_payroll !== null): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Pasa a Nómina</span>
                <span class="sgi-data-value">
                    <span class="badge bg-<?= $novelty->passes_payroll ? 'success' : 'secondary' ?>"><?= $novelty->passes_payroll ? 'Sí' : 'No' ?></span>
                </span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->approved_by_user): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Aprobado/Rechazado por</span>
                <span class="sgi-data-value"><?= h($novelty->approved_by_user->full_name ?? '—') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->approved_at): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha</span>
                <span class="sgi-data-value"><?= $novelty->approved_at->format('d/m/Y H:i') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->filing_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Diligenciamiento</span>
                <span class="sgi-data-value"><?= $novelty->filing_date->format('d/m/Y') ?></span>
            </div>
            <?php endif; ?>

            <!-- Liquidation doc link -->
            <?php if ($novelty->isGrouped()): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Documento de Liquidación</span>
                <span class="sgi-data-value">
                    <?= $this->Html->link(
                        $novelty->novelty_liquidation_doc->liquidation_number ?? 'Ver',
                        ['controller' => 'NoveltyLiquidationDocs', 'action' => 'view', $novelty->liquidation_doc_id],
                        ['class' => 'text-decoration-none']
                    ) ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Signatures -->
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
        <div class="sgi-section-title">Observaciones Generales</div>
        <div style="padding:.25rem 1.25rem .875rem;font-size:.875rem;color:#555;line-height:1.65;">
            <?= nl2br(h($novelty->observations)) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- RRHH Stage: Edit passes_payroll -->
    <?php if ($novelty->pipeline_status === NoveltyConstants::STATUS_RRHH && !$isRejected): ?>
    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <?= $this->Form->create(null, ['url' => ['action' => 'advance', $novelty->id]]) ?>
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Pasa a Nómina</label>
                <select name="passes_payroll" class="form-select" required>
                    <option value="">-- Seleccione --</option>
                    <option value="1" <?= $novelty->passes_payroll === true ? 'selected' : '' ?>>Sí</option>
                    <option value="0" <?= $novelty->passes_payroll === false ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-arrow-right me-1"></i>Avanzar a <?= $statusLabels[$nextStatus] ?? '' ?>
                </button>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
    <?php endif; ?>

    <!-- Contabilidad Stage: Assign to liquidation doc -->
    <?php if ($novelty->pipeline_status === NoveltyConstants::STATUS_CONTABILIDAD && !$novelty->isGrouped() && !$isRejected): ?>
    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <div class="sgi-section-title" style="padding:0 0 .5rem;">Asignar a Documento de Liquidación</div>
        <?= $this->Form->create(null, ['url' => ['action' => 'assignLiquidation', $novelty->id]]) ?>
        <div class="row g-3 align-items-end">
            <?php if (!empty($liquidationDocs)): ?>
            <div class="col-md-4">
                <label class="form-label">Documento existente</label>
                <select name="existing_doc_id" class="form-select">
                    <option value="">-- Crear nuevo --</option>
                    <?php foreach ($liquidationDocs as $docId => $docNumber): ?>
                    <option value="<?= $docId ?>"><?= h($docNumber) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 text-center pt-4"><strong>o</strong></div>
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label">Nuevo No. Liquidación</label>
                <input type="text" name="liquidation_number" class="form-control" placeholder="LIQ-001">
            </div>
            <div class="col-md-3">
                <label class="form-label">Período</label>
                <select name="period" class="form-select">
                    <?php foreach (NoveltyConstants::PERIOD_LABELS as $val => $label): ?>
                    <option value="<?= $val ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-link-45deg me-1"></i>Asignar
                </button>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
    <?php endif; ?>

    <!-- Advance/Reject buttons (for non-RRHH stages, non-grouped) -->
    <?php if ($canAdvance && $novelty->pipeline_status !== NoveltyConstants::STATUS_RRHH): ?>
    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <div class="d-flex gap-2">
            <?php if (empty($transitionErrors)): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'advance', $novelty->id], 'class' => 'd-inline']) ?>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-arrow-right me-1"></i>Avanzar a <?= $statusLabels[$nextStatus] ?? '' ?>
            </button>
            <?= $this->Form->end() ?>
            <?php else: ?>
            <div class="text-warning small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <?= implode('<br>', array_map('h', $transitionErrors)) ?>
            </div>
            <?php endif; ?>

            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="bi bi-x-lg me-1"></i>Rechazar
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Reject-only button for grouped novelties -->
    <?php if (!$isRejected && !$novelty->isPaid() && $novelty->isGrouped()): ?>
    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <div class="text-muted small mb-2">
            <i class="bi bi-info-circle me-1"></i>Esta novedad avanza desde su documento de liquidación.
        </div>
        <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal">
            <i class="bi bi-x-lg me-1"></i>Rechazar Individualmente
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Documents Section -->
<div class="card card-primary mb-4">
    <div class="card-header">
        <strong>Documentos</strong>
    </div>
    <div class="card-body">
        <?php if (!empty($documentsByStatus)): ?>
            <?php foreach ($documentsByStatus as $stage => $docs): ?>
            <div class="mb-3">
                <span class="sgi-section-label"><?= $statusLabels[$stage] ?? ucfirst($stage) ?></span>
                <ul class="list-group list-group-flush mt-1">
                    <?php foreach ($docs as $doc): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                        <div>
                            <a href="<?= $this->Url->build('/' . $doc->file_path) ?>" target="_blank" class="text-decoration-none">
                                <i class="bi bi-file-earmark me-1"></i><?= h($doc->file_name) ?>
                            </a>
                            <small class="text-muted ms-2"><?= h($doc->uploaded_by_user->full_name ?? '') ?></small>
                        </div>
                        <?php if ($doc->pipeline_status === $novelty->pipeline_status): ?>
                        <?= $this->Form->postLink(
                            '<i class="bi bi-trash"></i>',
                            ['action' => 'deleteDocument', $novelty->id, $doc->id],
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

        <?php if (!$isRejected && !$novelty->isPaid()): ?>
        <div class="mt-3 pt-3" style="border-top:1px solid var(--border-color);">
            <?= $this->Form->create(null, ['url' => ['action' => 'uploadDocument', $novelty->id], 'type' => 'file', 'class' => 'd-flex gap-2 align-items-end']) ?>
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
        <?php if (!empty($novelty->novelty_observations)): ?>
        <div style="max-height:400px;overflow-y:auto;" class="mb-3">
            <?php foreach ($novelty->novelty_observations as $obs): ?>
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

        <?php if (!$isRejected && !$novelty->isPaid()): ?>
        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $novelty->id], 'class' => 'd-flex gap-2']) ?>
        <input type="text" name="message" class="form-control form-control-sm" placeholder="Escribir observación..." required>
        <button type="submit" class="btn btn-sm btn-primary flex-shrink-0">
            <i class="bi bi-send me-1"></i>Enviar
        </button>
        <?= $this->Form->end() ?>
        <?php endif; ?>
    </div>
</div>

<!-- Reject modal -->
<?php if (!$isRejected && !$novelty->isPaid()): ?>
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
<?php endif; ?>

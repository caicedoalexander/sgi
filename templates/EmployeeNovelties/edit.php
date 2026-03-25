<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmployeeNovelty $novelty
 * @var array $effectiveStatuses
 * @var string|null $nextStatus
 * @var array $transitionErrors
 * @var bool $canAdvance
 * @var bool $isApprovalRejected
 * @var array $approversList
 * @var array $documentsByStatus
 * @var array $liquidationDocs
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Editar Novedad #' . $novelty->id);

$statusLabels = NoveltyConstants::STATUS_LABELS;
$statusIcons = NoveltyConstants::STATUS_ICONS;
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
$isRejected = $novelty->isRejected();
$currentStatus = $novelty->pipeline_status;
$sections = $visibleSections ?? ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas'];

$statusBadgeMap = [
    'registro' => 'bg-secondary',
    'aprobacion' => 'bg-warning text-dark',
    'rrhh' => 'bg-info text-dark',
    'contabilidad' => 'bg-primary',
    'revision_firmas' => 'bg-warning text-dark',
    'gdp' => 'bg-dark',
    'tesoreria' => 'bg-info',
    'pagada' => 'bg-success',
    'rechazada' => 'bg-danger',
];
$ps = [$statusLabels[$currentStatus] ?? 'Desconocido', $statusBadgeMap[$currentStatus] ?? 'bg-dark'];

// Documents prep
$showUploadSection = !$isRejected && !$novelty->isPaid();
$docIcon = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => 'bi-file-earmark-pdf',
    str_contains($mime ?? '', 'image') => 'bi-file-earmark-image',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => 'bi-file-earmark-word',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'bi-file-earmark-excel',
    default => 'bi-file-earmark',
};
$docIconColor = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => '#dc3545',
    str_contains($mime ?? '', 'image') => '#0dcaf0',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => '#0d6efd',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'var(--primary-color)',
    default => '#aaa',
};
$totalDocs = array_sum(array_map('count', $documentsByStatus));
$badgeColors = [
    'registro' => 'bg-secondary', 'aprobacion' => 'bg-warning text-dark', 'rrhh' => 'bg-info text-dark', 'contabilidad' => 'bg-primary',
    'revision_firmas' => 'bg-warning text-dark', 'gdp' => 'bg-dark', 'tesoreria' => 'bg-info', 'pagada' => 'bg-success',
];
?>

<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Novedad</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1"></i>Ver',
            ['action' => 'view', $novelty->id],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Grouped novelty alert -->
<?php if ($novelty->isGrouped()): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-4" style="border-left:3px solid #0dcaf0;">
    <i class="bi bi-link-45deg fs-5"></i>
    <div>
        Esta novedad pertenece al documento de liquidación
        <strong><?= $this->Html->link(
            h($novelty->novelty_liquidation_doc->liquidation_number ?? '#' . $novelty->liquidation_doc_id),
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $novelty->liquidation_doc_id],
            ['class' => 'alert-link']
        ) ?></strong>.
        Los cambios de estado se gestionan desde allí.
    </div>
</div>
<?php endif; ?>

<!-- Approval rejection alert -->
<?php if (!empty($isApprovalRejected)): ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-x-circle"></i>
    <span>Esta novedad fue <strong>rechazada</strong> por el aprobador. Edite los datos necesarios y reenvíe para aprobación.</span>
    <?= $this->Form->postLink(
        '<i class="bi bi-send me-1"></i>Reenviar para Aprobación',
        ['action' => 'resendApproval', $novelty->id],
        ['class' => 'btn btn-warning btn-sm ms-auto', 'escape' => false, 'confirm' => '¿Reenviar la solicitud de aprobación?']
    ) ?>
</div>
<?php endif; ?>

<!-- Advance warning -->
<?php if ($canAdvance && !$isRejected && !empty($transitionErrors)): ?>
<div class="alert alert-warning mb-4">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
        <div>
            <strong>Para avanzar al siguiente estado complete:</strong>
            <ul class="mb-0 mt-1 ps-3">
                <?php foreach ($transitionErrors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Two-column layout -->
<div style="display:flex;gap:1.5rem;align-items:flex-start;">

<!-- Left column: main form -->
<div style="flex:1;min-width:0;">
<div class="card card-primary mb-4">

    <!-- Card header -->
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;">
                    <?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?>
                </div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                    <?= h($novelty->novelty_type->name ?? '') ?>
                </div>
            </div>
        </div>
        <span class="badge <?= $ps[1] ?>"><?= $ps[0] ?></span>
    </div>

    <!-- Pipeline progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?php
        $noveltyPipelineLabels = $statusLabels;
        $noveltyPipelineLabels[NoveltyConstants::STATUS_CONTABILIDAD] = 'Paso a Nómina';
        ?>
        <?= $this->element('pipeline_progress', [
            'pipelineStatuses' => $noveltyStatuses ?? $effectiveStatuses,
            'pipelineLabels' => $noveltyPipelineLabels,
            'currentStatus' => $currentStatus,
            'isRejected' => $isRejected,
            'statusIcons' => $statusIcons,
        ]) ?>
    </div>

    <!-- Ficha resumen (ledger) -->
    <div style="padding:1rem 1.5rem .75rem;">
        <div class="sgi-ledger">
            <div class="sgi-ledger-item" style="grid-column:span 2;">
                <div class="sgi-ledger-label">Empleado</div>
                <div class="sgi-ledger-value"><?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Tipo de Novedad</div>
                <div class="sgi-ledger-value"><?= h($novelty->novelty_type->name ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Diligenciamiento</div>
                <div class="sgi-ledger-value"><?= $novelty->filing_date?->format('d/m/Y') ?? '—' ?></div>
            </div>
            <?php if ($novelty->permission_date): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Fecha del Permiso</div>
                <div class="sgi-ledger-value"><?= $novelty->permission_date->format('d/m/Y') ?></div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Horario</div>
                <div class="sgi-ledger-value"><?= $scheduleLabels[$novelty->schedule_type] ?? h($novelty->schedule_type) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_DAYS): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Fecha Inicio</div>
                <div class="sgi-ledger-value"><?= $novelty->start_date?->format('d/m/Y') ?: '—' ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Fecha Fin</div>
                <div class="sgi-ledger-value"><?= $novelty->end_date?->format('d/m/Y') ?: '—' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_HOURS): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Hora Salida</div>
                <div class="sgi-ledger-value"><?= h($novelty->start_time) ?: '—' ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Hora Entrada</div>
                <div class="sgi-ledger-value"><?= h($novelty->end_time) ?: '—' ?></div>
            </div>
            <?php endif; ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Remunerado</div>
                <div class="sgi-ledger-value">
                    <span class="badge bg-<?= $novelty->is_paid ? 'success' : 'secondary' ?>"><?= $novelty->is_paid ? 'Sí' : 'No' ?></span>
                </div>
            </div>
            <?php if ($novelty->approved_by_user): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Aprobador</div>
                <div class="sgi-ledger-value"><?= h($novelty->approved_by_user->full_name ?? '—') ?></div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->area_approval): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Estado Aprobación</div>
                <div class="sgi-ledger-value">
                    <?php
                    $approvalBadge = match($novelty->area_approval) {
                        NoveltyConstants::APPROVAL_APPROVED => 'bg-success',
                        NoveltyConstants::APPROVAL_REJECTED => 'bg-danger',
                        default => 'bg-warning text-dark',
                    };
                    ?>
                    <span class="badge <?= $approvalBadge ?>"><?= h($novelty->area_approval) ?></span>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->approved_at): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Fecha Aprobación</div>
                <div class="sgi-ledger-value"><?= $novelty->approved_at->format('d/m/Y H:i') ?></div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->reason): ?>
            <div class="sgi-ledger-item" style="grid-column:span 4;">
                <div class="sgi-ledger-label">Motivo</div>
                <div class="sgi-ledger-value" style="white-space:normal;font-weight:400;font-size:.8rem;line-height:1.5;">
                    <?= nl2br(h($novelty->reason)) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($novelty->novelty_massive_employees)): ?>
        <div class="mt-2">
            <span style="font-size:.55rem;text-transform:uppercase;letter-spacing:.14em;color:#aaa;font-weight:600;">Empleados (Masiva)</span>
            <div class="mt-1">
                <?php foreach ($novelty->novelty_massive_employees as $me): ?>
                    <span class="badge bg-light text-dark me-1 mb-1"><?= h($me->employee->full_name ?? '—') ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="card-body p-4" style="padding-top:0 !important;">

        <?php $hasEditableFields = !empty($editableFields ?? []); ?>
        <?php if ($hasEditableFields): ?>
        <!-- Section: Gestión (RRHH fields) -->
        <?php if (in_array('rrhh', $sections)): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-gear me-1"></i>Gestión
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Registrado por</label>
                    <input type="text" class="form-control" disabled
                           value="<?= h($novelty->registered_by_user->full_name ?? '—') ?>">
                </div>
                <?php if ($novelty->rrhh_by_user): ?>
                <div class="col-md-4">
                    <label class="form-label">Procesado RRHH</label>
                    <input type="text" class="form-control" disabled
                           value="<?= h($novelty->rrhh_by_user->full_name ?? '—') ?>">
                </div>
                <?php endif; ?>
                <?php if ($novelty->passes_payroll !== null): ?>
                <div class="col-md-4">
                    <label class="form-label">Pasa a Nómina</label>
                    <div class="pt-1">
                        <span class="badge bg-<?= $novelty->passes_payroll ? 'success' : 'secondary' ?>">
                            <?= $novelty->passes_payroll ? 'Sí' : 'No' ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Liquidation doc link -->
            <?php if ($novelty->isGrouped()): ?>
            <div class="mt-3">
                <label class="form-label">Documento de Liquidación</label>
                <div>
                    <?= $this->Html->link(
                        '<i class="bi bi-link-45deg me-1"></i>' . h($novelty->novelty_liquidation_doc->liquidation_number ?? 'Ver'),
                        ['controller' => 'NoveltyLiquidationDocs', 'action' => 'view', $novelty->liquidation_doc_id],
                        ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false]
                    ) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Signatures -->
        <?php if (in_array('firmas', $sections) && $novelty->employee_signature): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-pen me-1"></i>Firmas
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small">Firma del Funcionario</label>
                    <div>
                        <img src="<?= $this->Url->build('/' . $novelty->employee_signature) ?>" alt="Firma"
                             style="max-width:300px;max-height:120px;border:1px solid var(--border-color);">
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Approver section (when in aprobacion status) -->
        <?php if (in_array('aprobacion', $sections) && $novelty->pipeline_status === NoveltyConstants::STATUS_APROBACION): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-person-check me-1"></i>Aprobación
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <?= $this->Form->create(null, ['url' => ['action' => 'advance', $novelty->id]]) ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Aprobador</label>
                    <?= $this->Form->control('approver_id', [
                        'label' => false,
                        'options' => $approversList ?? [],
                        'empty' => '— Seleccione —',
                        'class' => 'form-select select2',
                        'value' => $novelty->approver_id,
                    ]) ?>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
        <?php endif; ?>

        <!-- Stage-specific actions -->
        <?php if ($novelty->pipeline_status === NoveltyConstants::STATUS_RRHH && !$isRejected): ?>
        <div class="pt-3" style="border-top:1px solid var(--border-color);">
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
                        <i class="bi bi-arrow-right-circle me-1"></i>Guardar y Avanzar a <?= $statusLabels[$nextStatus] ?? '' ?>
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
        <?php endif; ?>

        <?php if (in_array('contabilidad', $sections) && $novelty->pipeline_status === NoveltyConstants::STATUS_CONTABILIDAD && !$novelty->isGrouped() && !$isRejected): ?>
        <div class="pt-3" style="border-top:1px solid var(--border-color);">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-file-earmark-text me-1"></i>Asignar a Documento de Liquidación
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
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

        <!-- Advance/Reject buttons (non-RRHH stages) -->
        <?php if ($canAdvance && $currentStatus !== NoveltyConstants::STATUS_RRHH): ?>
        <div class="d-flex gap-2 pt-3" style="border-top:1px solid var(--border-color);">
            <?php if (empty($transitionErrors)): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'advance', $novelty->id], 'class' => 'd-inline']) ?>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-arrow-right-circle me-1"></i>Avanzar a <?= $statusLabels[$nextStatus] ?? '' ?>
            </button>
            <?= $this->Form->end() ?>
            <?php endif; ?>
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="bi bi-x-lg me-1"></i>Rechazar
            </button>
        </div>
        <?php endif; ?>


        <?php else: ?>
        <div class="alert alert-info mb-0">
            <i class="bi bi-info-circle me-1"></i>
            No tiene permisos de edición para esta novedad en el estado actual.
        </div>
        <?php endif; ?>

    </div>
</div>
</div><!-- /left column -->

<!-- Right column: documents + observations -->
<div style="width:380px;flex-shrink:0;display:flex;flex-direction:column;gap:1rem;">

<!-- Documents panel -->
<div class="card card-primary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
        <?php if ($showUploadSection): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
            <i class="bi bi-upload me-1"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($documentsByStatus)): ?>
        <div style="padding:2rem 1rem;text-align:center;color:#c8c8c8;">
            <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
            <span style="font-size:.8rem;">Sin soportes adjuntos</span>
        </div>
    <?php else: ?>
        <div style="max-height:420px;overflow-y:auto;">
            <?php
            $multipleStatuses = count($documentsByStatus) > 1;
            foreach ($documentsByStatus as $status => $docs):
            ?>
            <?php if ($multipleStatuses): ?>
            <div style="padding:.3rem .875rem;background:#f8f9fa;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.4rem;">
                <span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.6rem;"><?= $statusLabels[$status] ?? $status ?></span>
                <span style="font-size:.67rem;color:#aaa;"><?= count($docs) ?> archivo<?= count($docs) !== 1 ? 's' : '' ?></span>
            </div>
            <?php endif; ?>
            <?php foreach ($docs as $doc): ?>
            <div style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">
                <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
                    <i class="bi <?= $docIcon($doc->mime_type) ?>"
                       style="color:<?= $docIconColor($doc->mime_type) ?>;font-size:1rem;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"
                         title="<?= h($doc->file_name) ?>">
                        <?= h($doc->file_name) ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
                        <?php if (!$multipleStatuses): ?>
                        <span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.58rem;"><?= $statusLabels[$status] ?? $status ?></span>
                        <?php endif; ?>
                        <span style="font-size:.65rem;color:#bbb;">
                            <i class="bi bi-clock" style="font-size:.6rem;"></i>
                            <?= $doc->created?->format('d/m/Y H:i') ?>
                        </span>
                        <?php if ($doc->file_size): ?>
                        <span style="font-size:.63rem;color:#ccc;"><?= $this->Number->toReadableSize($doc->file_size) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:.25rem;flex-shrink:0;align-self:center;">
                    <?= $this->Html->link(
                        '<i class="bi bi-box-arrow-up-right"></i>',
                        '/' . $doc->file_path,
                        ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
                    ) ?>
                    <?php if ($showUploadSection && $doc->pipeline_status === $currentStatus): ?>
                    <?= $this->Form->postLink(
                        '<i class="bi bi-trash"></i>',
                        ['action' => 'deleteDocument', $novelty->id, $doc->id],
                        ['confirm' => '¿Eliminar este soporte?', 'class' => 'btn btn-sm btn-outline-danger', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'title' => 'Eliminar']
                    ) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Observations chat -->
<?php $obsCount = count($novelty->novelty_observations ?? []); ?>
<div class="card card-primary" style="display:flex;flex-direction:column;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <?php if ($obsCount > 0): ?>
        <span class="sgi-folder-count ms-auto"><?= $obsCount ?></span>
        <?php endif; ?>
    </div>

    <div id="obs-chat-scroll" style="min-height:100px;max-height:340px;overflow-y:auto;padding:1rem .875rem;background:#f9fafb;display:flex;flex-direction:column;gap:.875rem;">
        <?php if (empty($novelty->novelty_observations)): ?>
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1.5rem 0;color:#c5c5c5;gap:.5rem;">
            <i class="bi bi-chat-square-dots" style="font-size:1.75rem;"></i>
            <span style="font-size:.78rem;">Sin observaciones aún</span>
        </div>
        <?php else: ?>
        <?php foreach ($novelty->novelty_observations as $obs):
            $isMine = $currentUser && $obs->user_id === $currentUser->id;
            $names = explode(' ', trim($obs->user->full_name ?? ''));
            $initials = strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[array_key_last($names)] ?? '', 0, 1));
        ?>
        <div style="display:flex;flex-direction:column;align-items:<?= $isMine ? 'flex-end' : 'flex-start' ?>;gap:.2rem;">
            <div style="font-size:.63rem;color:#aaa;font-weight:500;letter-spacing:.01em;
                        <?= $isMine ? 'padding-right:.3rem' : 'padding-left:.3rem' ?>">
                <?= $isMine ? 'Tú' : h($obs->user->full_name ?? '') ?>
            </div>
            <div style="max-width:92%;padding:.55rem .8rem;font-size:.81rem;line-height:1.5;word-break:break-word;
                        background:<?= $isMine ? 'var(--primary-color)' : '#fff' ?>;
                        color:<?= $isMine ? '#fff' : '#2d2d2d' ?>;
                        border:1px solid <?= $isMine ? 'var(--primary-color)' : 'var(--border-color)' ?>;
                        border-radius:<?= $isMine ? '10px 10px 2px 10px' : '10px 10px 10px 2px' ?>;">
                <?= nl2br(h($obs->message)) ?>
            </div>
            <div style="font-size:.61rem;color:#c0c0c0;
                        <?= $isMine ? 'padding-right:.3rem' : 'padding-left:.3rem' ?>">
                <?= $obs->created?->format('d/m/Y H:i') ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!$isRejected && !$novelty->isPaid()): ?>
    <div style="border-top:1px solid var(--border-color);padding:.75rem .875rem;background:#fff;">
        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $novelty->id]]) ?>
        <div class="d-flex gap-2 align-items-end">
            <textarea name="message" class="form-control auto-resize" rows="1"
                      style="font-size:.82rem;background:#f9fafb;border-color:var(--border-color);"
                      placeholder="Escriba una observación..." required></textarea>
            <button type="submit" class="btn btn-primary flex-shrink-0"
                    style="padding:.5rem .75rem;align-self:flex-end;" title="Enviar">
                <i class="bi bi-send" style="font-size:.85rem;"></i>
            </button>
        </div>
        <?= $this->Form->end() ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /right column -->
</div><!-- /two-column layout -->

<!-- Upload Document Modal -->
<?php if ($showUploadSection): ?>
<div class="modal fade" id="uploadDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'uploadDocument', $novelty->id], 'type' => 'file']) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Subir Soporte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Archivo</label>
                    <input type="file" name="document" class="form-control" required
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                    <div class="form-text">Máximo 10 MB — PDF, imágenes, Word o Excel.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Subir</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reject Modal -->
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

<?php $this->append('script') ?>
<script>
(function(){
    var chat = document.getElementById('obs-chat-scroll');
    if (chat) chat.scrollTop = chat.scrollHeight;

    function syncHeight(el) {
        el.style.height = '0px';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }
    document.querySelectorAll('textarea.auto-resize').forEach(function(el) {
        el.style.overflow  = 'hidden';
        el.style.resize    = 'none';
        el.style.minHeight = '0px';
        syncHeight(el);
        el.addEventListener('input', function() { syncHeight(this); });
    });
})();
</script>
<?php $this->end() ?>

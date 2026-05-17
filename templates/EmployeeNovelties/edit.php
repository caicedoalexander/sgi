<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\EmployeeNoveltyEditViewModel $viewModel
 * @var \App\Model\Entity\User|null $currentUser
 */

use App\Constants\NoveltyConstants;
use App\View\Presentation\NoveltyPresentation;

$this->assign('title', $viewModel->pageTitle);

// Alias locales para mantener el markup corto.
$novelty            = $viewModel->novelty;
$roleName           = $viewModel->roleName;
$editableFields     = $viewModel->editableFields;
$visibleSections    = $viewModel->visibleSections;
$effectiveStatuses  = $viewModel->effectiveStatuses;
$noveltyStatuses    = $viewModel->noveltyStatuses;
$nextStatus         = $viewModel->nextStatus;
$transitionErrors   = $viewModel->transitionErrors;
$canAdvance         = $viewModel->canAdvance;
$isApprovalRejected = $viewModel->isApprovalRejected;
$approversList      = $viewModel->approversList;
$documentsByStatus  = $viewModel->documentsByStatus;
$liquidationDocs    = $viewModel->liquidationDocs;
$emailLogs          = $viewModel->emailLogs;

$statusLabels       = $viewModel->statusLabels;
$statusIcons        = $viewModel->statusIcons;
$scheduleLabels     = $viewModel->scheduleLabels;
$statusBadgeMap     = $viewModel->statusBadgeMap;
$badgeColors        = $viewModel->badgeColors;
$isRejected         = $viewModel->isRejected;
$currentStatus      = $viewModel->currentStatus;
$sections           = $viewModel->sections;
$ps                 = $viewModel->currentStatusBadge;
$showUploadSection  = $viewModel->showUploadSection;
$totalDocs          = $viewModel->totalDocs;
?>
<?= $this->element('cdn_select2') ?>

<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Novedad</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1" aria-hidden="true"></i>Ver',
            ['action' => 'view', $novelty->id],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Grouped novelty alert -->
<?php if ($novelty->isGrouped()): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-4" style="border-left:3px solid var(--info-color);">
    <i class="bi bi-link-45deg fs-5" aria-hidden="true"></i>
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
    <i class="bi bi-x-circle" aria-hidden="true"></i>
    <span>Esta novedad fue <strong>rechazada</strong> por el aprobador. Edite los datos necesarios y reenvíe para aprobación.</span>
    <?= $this->Form->postLink(
        '<i class="bi bi-send me-1" aria-hidden="true"></i>Reenviar para Aprobación',
        ['action' => 'resendApproval', $novelty->id],
        ['class' => 'btn btn-warning btn-sm ms-auto', 'escape' => false, 'confirm' => '¿Reenviar la solicitud de aprobación?']
    ) ?>
</div>
<?php endif; ?>

<!-- Advance warning -->
<?php if ($canAdvance && !$isRejected && !empty($transitionErrors)): ?>
<div class="alert alert-warning mb-4">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
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
            <div class="sgi-icon-chip">
                <i class="bi bi-calendar-check" aria-hidden="true"></i>
            </div>
            <div>
                <div class="sgi-card-title">
                    <?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?>
                </div>
                <div class="sgi-card-subtitle mt-1">
                    <?= h($novelty->novelty_type->name ?? '') ?>
                </div>
            </div>
        </div>
        <span class="pill <?= $ps[1] ?>"><?= $ps[0] ?></span>
    </div>

    <!-- Pipeline progress -->
    <div class="sgi-pipeline-wrapper">
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
    <div class="sgi-ledger-wrapper">
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
                    <span class="pill pill-<?= $novelty->is_paid ? 'primary-soft' : 'muted' ?>"><?= $novelty->is_paid ? 'Sí' : 'No' ?></span>
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
                    <?php $approvalBadge = NoveltyPresentation::APPROVAL_BADGES[$novelty->area_approval] ?? 'pill-warning-soft'; ?>
                    <span class="pill <?= $approvalBadge ?>"><?= h($novelty->area_approval) ?></span>
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
                    <span class="pill pill-muted me-1 mb-1"><?= h($me->employee->full_name ?? '—') ?></span>
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
                    <i class="bi bi-gear me-1" aria-hidden="true"></i>Gestión
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
                        <span class="pill pill-<?= $novelty->passes_payroll ? 'primary-soft' : 'muted' ?>">
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
                        '<i class="bi bi-link-45deg me-1" aria-hidden="true"></i>' . h($novelty->novelty_liquidation_doc->liquidation_number ?? 'Ver'),
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
                    <i class="bi bi-pen me-1" aria-hidden="true"></i>Firmas
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
        <?php if (in_array('aprobacion', $sections) && $novelty->pipeline_status === NoveltyConstants::STATUS_APROBACION && !$isRejected): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-person-check me-1" aria-hidden="true"></i>Aprobación
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <?= $this->Form->create(null, ['url' => ['action' => 'resendApproval', $novelty->id]]) ?>
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
                <div class="col-md-6">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-send me-1" aria-hidden="true"></i>Enviar link de aprobación
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
        <?php endif; ?>

        <!-- Stage-specific actions -->
        <?php if ($novelty->pipeline_status === NoveltyConstants::STATUS_RRHH && !$isRejected): ?>
        <div class="pt-3" style="border-top:1px solid var(--border-color);">
            <?= $this->Form->create(null, ['url' => ['action' => 'advance', $novelty->id]]) ?>
            <?= $this->Form->hidden('expected_status', ['value' => $novelty->pipeline_status]) ?>
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
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Guardar y Avanzar a <?= $statusLabels[$nextStatus] ?? '' ?>
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
        <?php endif; ?>

        <?php if (in_array('contabilidad', $sections) && $novelty->pipeline_status === NoveltyConstants::STATUS_CONTABILIDAD && !$novelty->isGrouped() && !$isRejected): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Asignar a Documento de Liquidación
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
                        <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Asignar
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
        <?php endif; ?>

        <!-- Advance/Reject buttons (non-RRHH stages) -->
        <?php if ($canAdvance && !in_array($currentStatus, [NoveltyConstants::STATUS_RRHH, NoveltyConstants::STATUS_APROBACION])): ?>
        <div class="d-flex gap-2 pt-3" style="border-top:1px solid var(--border-color);">
            <?php if (empty($transitionErrors)): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'advance', $novelty->id], 'class' => 'd-inline']) ?>
            <?= $this->Form->hidden('expected_status', ['value' => $novelty->pipeline_status]) ?>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Avanzar a <?= $statusLabels[$nextStatus] ?? '' ?>
            </button>
            <?= $this->Form->end() ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>


        <?php else: ?>
        <div class="alert alert-info mb-0">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            No tiene permisos de edición para esta novedad en el estado actual.
        </div>
        <?php endif; ?>

    </div>
</div>

<?= $this->element('email_log_panel', ['emailLogs' => $emailLogs ?? []]) ?>
</div><!-- /left column -->

<!-- Right column: documents + observations -->
<div style="width:380px;flex-shrink:0;display:flex;flex-direction:column;gap:1rem;">

<!-- Documents panel -->
<div class="card card-primary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;" aria-hidden="true"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
        <?php if ($showUploadSection): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
            <i class="bi bi-upload me-1" aria-hidden="true"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <div id="docs-empty-state" style="padding:2rem 1rem;text-align:center;color:#c8c8c8;<?= !empty($documentsByStatus) ? 'display:none;' : '' ?>">
        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;" aria-hidden="true"></i>
        <span style="font-size:.8rem;">Sin soportes adjuntos</span>
    </div>
    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
        <?php
        $multipleStatuses = count($documentsByStatus) > 1;
        foreach ($documentsByStatus as $status => $docs):
        ?>
        <?php if ($multipleStatuses): ?>
        <div style="padding:.3rem .875rem;background:var(--bg-subtle);border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.4rem;">
            <span class="pill <?= $badgeColors[$status] ?? 'pill-muted' ?>" style="font-size:.6rem;"><?= $statusLabels[$status] ?? $status ?></span>
            <span style="font-size:.67rem;color:#aaa;"><?= count($docs) ?> archivo<?= count($docs) !== 1 ? 's' : '' ?></span>
        </div>
        <?php endif; ?>
        <?php foreach ($docs as $doc): ?>
            <?= $this->element('document_row', [
                'doc'          => $doc,
                'canDelete'    => $showUploadSection && $doc->pipeline_status === $currentStatus,
                'deleteUrl'    => $this->Url->build(['controller' => 'NoveltyDocuments', 'action' => 'delete', $novelty->id, $doc->id]),
                'showBadge'    => !$multipleStatuses,
                'badgeColors'  => $badgeColors,
                'statusLabels' => $statusLabels,
            ]) ?>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>

<!-- Observations chat -->
<?php $obsCount = count($novelty->novelty_observations ?? []); ?>
<div class="card card-primary sgi-obs-card" style="display:flex;flex-direction:column;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);" aria-hidden="true"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <span id="obs-count" class="sgi-folder-count ms-auto" <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
    </div>

    <div id="obs-chat-scroll" class="sgi-obs-list">
        <?php foreach ($novelty->novelty_observations ?? [] as $obs): ?>
            <?= $this->element('observation_bubble', [
                'observation' => $obs,
                'isMine' => $currentUser && $obs->user_id === $currentUser->id,
            ]) ?>
        <?php endforeach; ?>
    </div>

    <div id="obs-empty-state" class="sgi-obs-empty" <?= $obsCount > 0 ? 'hidden' : '' ?>>
        <i class="bi bi-chat-square-dots" style="font-size:1.75rem;" aria-hidden="true"></i>
        <span style="font-size:.78rem;">Sin observaciones aún</span>
    </div>

    <?php if (!$isRejected && !$novelty->isPaid()): ?>
    <div class="sgi-obs-input-bar">
        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $novelty->id], 'id' => 'obs-form']) ?>
        <div class="sgi-obs-compose">
            <textarea name="message" class="auto-resize" rows="1"
                      placeholder="Escriba una observación..."></textarea>
            <button type="submit" class="sgi-obs-compose-send" title="Enviar">
                <i class="bi bi-send" aria-hidden="true"></i>
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
            <form id="upload-doc-form"
                  data-url="<?= $this->Url->build(['controller' => 'NoveltyDocuments', 'action' => 'upload', $novelty->id]) ?>"
                  enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-2" aria-hidden="true"></i>Subir Soporte</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Archivo</label>
                        <input type="file" name="file" class="form-control" required
                               accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                        <div class="form-text">Máximo <?= h(\App\Constants\UploadConstants::MAX_BYTES_LABEL) ?> — PDF, imágenes, Word o Excel.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Subir</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?= $this->element('document_row_template', ['showBadge' => true]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>
<?= $this->element('observation_chat_init') ?>

<?php $this->append('script') ?>
<script>
(function(){
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.card.card-primary .card-header .sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>
<?php $this->end() ?>

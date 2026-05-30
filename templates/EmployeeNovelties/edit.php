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

<?php
$novIdLabel = $novelty->employee->full_name ?? ('Novedad #' . $novelty->id);
?>
<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Novedades', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <?= $this->Html->link(h($novIdLabel), ['action' => 'view', $novelty->id]) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Editar</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title">Editar Novedad</span>
            <span class="sgi-edit-id-chip">#<?= h((string)$novelty->id) ?></span>
            <?php if ($isRejected): ?>
                <span class="pill pill-danger-soft">Rechazada</span>
            <?php else: ?>
                <span class="pill <?= h($ps[1]) ?>"><?= h($ps[0]) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye" aria-hidden="true"></i>Ver',
            ['action' => 'view', $novelty->id],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Grouped novelty alert -->
<?php if ($novelty->isGrouped()): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-4">
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

<?php
$noveltyPipelineLabels = $statusLabels;
$noveltyPipelineLabels[NoveltyConstants::STATUS_CONTABILIDAD] = 'Paso a Nómina';
$pipelineStepsToShow = $noveltyStatuses ?? $effectiveStatuses;
$isNovTerminal = $currentStatus === NoveltyConstants::STATUS_PAGADA;
?>
<div class="sgi-invoice-view-grid view-anim">

    <!-- ═════════════════════ SIDEBAR ═════════════════════ -->
    <aside class="sgi-invoice-view-left">
        <?php
        $registryLines = [];
        if ($novelty->registered_by_user) {
            $registryLines[] = ['icon' => 'bi-person', 'html' => 'Registrado por ' . h($novelty->registered_by_user->full_name)];
        }
        if ($novelty->filing_date) {
            $registryLines[] = ['icon' => 'bi-calendar3', 'html' => 'Diligenciada · <span class="mono">' . $novelty->filing_date->format('d/m/Y') . '</span>'];
        }
        if ($novelty->modified) {
            $registryLines[] = ['icon' => 'bi-pencil-square', 'html' => 'Modificada · <span class="mono">' . $novelty->modified->format('d/m/Y') . '</span>'];
        }

        echo $this->element('pipeline_sidebar', [
            'icon'           => 'calendar-check',
            'idLabel'        => $novelty->custom_name ?: ($novelty->employee->full_name ?? ('Novedad #' . $novelty->id)),
            'typeLabel'      => $novelty->novelty_type->name ?? null,
            'statusPill'     => $ps[1],
            'statusLabel'    => $ps[0],
            'isRejected'     => $isRejected,
            'entityLabel'    => 'Fecha del Permiso',
            'entityValue'    => $novelty->permission_date?->format('d/m/Y') ?? '—',
            'entitySubLabel' => $scheduleLabels[$novelty->schedule_type] ?? null,
            'entitySubIcon'  => 'bi-clock',
            'amountLabel'    => null,
            'amount'         => null,
            'pipelineSteps'  => $pipelineStepsToShow,
            'pipelineLabels' => $noveltyPipelineLabels,
            'currentStatus'  => $currentStatus,
            'isTerminal'     => $isNovTerminal,
            'modifiedAt'     => $novelty->modified ?? null,
            'registryLines'  => $registryLines,
        ]);
        ?>
    </aside>

    <!-- ═════════════════════ CONTENIDO ═════════════════════ -->
    <main class="sgi-invoice-view-right">

    <!-- Información de la novedad -->
    <div class="card" style="padding:18px 20px;">
        <div class="sgi-section-head" style="margin-bottom:12px;">
            <span class="sgi-label">Información de la novedad</span>
        </div>
        <div class="sgi-info-grid">
            <div class="sgi-info-cell" style="grid-column:span 2;">
                <div class="sgi-info-cell-label">Empleado</div>
                <div class="sgi-info-cell-value"><?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?></div>
            </div>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Tipo de Novedad</div>
                <div class="sgi-info-cell-value"><?= h($novelty->novelty_type->name ?? '—') ?></div>
            </div>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Diligenciamiento</div>
                <div class="sgi-info-cell-value mono"><?= $novelty->filing_date?->format('d/m/Y') ?? '—' ?></div>
            </div>
            <?php if ($novelty->permission_date): ?>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Fecha del Permiso</div>
                <div class="sgi-info-cell-value mono"><?= $novelty->permission_date->format('d/m/Y') ?></div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type): ?>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Horario</div>
                <div class="sgi-info-cell-value"><?= $scheduleLabels[$novelty->schedule_type] ?? h($novelty->schedule_type) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_DAYS): ?>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Fecha Inicio</div>
                <div class="sgi-info-cell-value mono"><?= $novelty->start_date?->format('d/m/Y') ?: '—' ?></div>
            </div>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Fecha Fin</div>
                <div class="sgi-info-cell-value mono"><?= $novelty->end_date?->format('d/m/Y') ?: '—' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_HOURS): ?>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Hora Salida</div>
                <div class="sgi-info-cell-value mono"><?= h($novelty->start_time) ?: '—' ?></div>
            </div>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Hora Entrada</div>
                <div class="sgi-info-cell-value mono"><?= h($novelty->end_time) ?: '—' ?></div>
            </div>
            <?php endif; ?>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Remunerado</div>
                <div class="sgi-info-cell-value">
                    <span class="pill pill-<?= $novelty->is_paid ? 'primary-soft' : 'muted' ?>"><?= $novelty->is_paid ? 'Sí' : 'No' ?></span>
                </div>
            </div>
            <?php if ($novelty->approved_by_user): ?>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Aprobador</div>
                <div class="sgi-info-cell-value"><?= h($novelty->approved_by_user->full_name ?? '—') ?></div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->area_approval): ?>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Estado Aprobación</div>
                <div class="sgi-info-cell-value">
                    <?php $approvalBadge = NoveltyPresentation::APPROVAL_BADGES[$novelty->area_approval] ?? 'pill-warning-soft'; ?>
                    <span class="pill <?= $approvalBadge ?>"><?= h($novelty->area_approval) ?></span>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->approved_at): ?>
            <div class="sgi-info-cell">
                <div class="sgi-info-cell-label">Fecha Aprobación</div>
                <div class="sgi-info-cell-value mono"><?= $novelty->approved_at->format('d/m/Y H:i') ?></div>
            </div>
            <?php endif; ?>
            <?php if ($novelty->reason): ?>
            <div class="sgi-info-cell" style="grid-column:span 4;">
                <div class="sgi-info-cell-label">Motivo</div>
                <div class="sgi-info-cell-value" style="white-space:normal;font-weight:400;font-size:.8rem;line-height:1.5;">
                    <?= nl2br(h($novelty->reason)) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($novelty->novelty_massive_employees)): ?>
        <div class="mt-3">
            <span class="sgi-label">Empleados (Masiva)</span>
            <div class="mt-1 d-flex flex-wrap gap-1">
                <?php foreach ($novelty->novelty_massive_employees as $me): ?>
                    <span class="pill pill-muted"><?= h($me->employee->full_name ?? '—') ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="card" style="padding:20px;">

        <?php $hasEditableFields = !empty($editableFields ?? []); ?>
        <?php if ($hasEditableFields): ?>
        <!-- Section: Gestión (RRHH fields) -->
        <?php if (in_array('rrhh', $sections)): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="sgi-label flex-shrink-0"><i class="bi bi-gear me-1" aria-hidden="true"></i>Gestión</span>
                <div class="hr"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="input-label">Registrado por</label>
                    <input type="text" class="form-control" disabled
                           value="<?= h($novelty->registered_by_user->full_name ?? '—') ?>">
                </div>
                <?php if ($novelty->rrhh_by_user): ?>
                <div class="col-md-4">
                    <label class="input-label">Procesado RRHH</label>
                    <input type="text" class="form-control" disabled
                           value="<?= h($novelty->rrhh_by_user->full_name ?? '—') ?>">
                </div>
                <?php endif; ?>
                <?php if ($novelty->passes_payroll !== null): ?>
                <div class="col-md-4">
                    <label class="input-label">Pasa a Nómina</label>
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
                <label class="input-label">Documento de Liquidación</label>
                <div>
                    <?= $this->Html->link(
                        '<i class="bi bi-link-45deg me-1" aria-hidden="true"></i>' . h($novelty->novelty_liquidation_doc->liquidation_number ?? 'Ver'),
                        ['controller' => 'NoveltyLiquidationDocs', 'action' => 'view', $novelty->liquidation_doc_id],
                        ['class' => 'btn btn-sm btn-ghost-card', 'escape' => false]
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
                <span class="sgi-label flex-shrink-0"><i class="bi bi-pen me-1" aria-hidden="true"></i>Firmas</span>
                <div class="hr"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="input-label">Firma del Funcionario</label>
                    <div>
                        <img src="<?= $this->Url->build('/' . $novelty->employee_signature) ?>" alt="Firma"
                             style="max-width:300px;max-height:120px;background:var(--bg-subtle);padding:6px;">
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Approver section (when in aprobacion status) -->
        <?php if (in_array('aprobacion', $sections) && $novelty->pipeline_status === NoveltyConstants::STATUS_APROBACION && !$isRejected): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="sgi-label flex-shrink-0"><i class="bi bi-person-check me-1" aria-hidden="true"></i>Aprobación</span>
                <div class="hr"></div>
            </div>
            <?= $this->Form->create(null, ['url' => ['action' => 'resendApproval', $novelty->id]]) ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="input-label">Aprobador</label>
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
        <div class="pt-3" style="border-top:1px solid var(--rule);">
            <?= $this->Form->create(null, ['url' => ['action' => 'advance', $novelty->id]]) ?>
            <?= $this->Form->hidden('expected_status', ['value' => $novelty->pipeline_status]) ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="input-label">Pasa a Nómina</label>
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
                <span class="sgi-label flex-shrink-0"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Asignar a Documento de Liquidación</span>
                <div class="hr"></div>
            </div>
            <?= $this->Form->create(null, ['url' => ['action' => 'assignLiquidation', $novelty->id]]) ?>
            <div class="row g-3 align-items-end">
                <?php if (!empty($liquidationDocs)): ?>
                <div class="col-md-4">
                    <label class="input-label">Documento existente</label>
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
                    <label class="input-label">Nuevo No. Liquidación</label>
                    <input type="text" name="liquidation_number" class="form-control" placeholder="LIQ-001">
                </div>
                <div class="col-md-3">
                    <label class="input-label">Período</label>
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
        <div class="d-flex gap-2 pt-3" style="border-top:1px solid var(--rule);">
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

    </div><!-- /card Gestión -->

<?= $this->element('email_log_panel', ['emailLogs' => $emailLogs ?? []]) ?>

<!-- Soportes -->
<?php
$multipleStatuses = count($documentsByStatus) > 1;
$noveltyDocGroups = [];
foreach ($documentsByStatus as $status => $statusDocs) {
    $rows = [];
    foreach ($statusDocs as $doc) {
        $rows[] = [
            'doc'          => $doc,
            'canDelete'    => $showUploadSection && $doc->pipeline_status === $currentStatus,
            'deleteUrl'    => $this->Url->build(['controller' => 'NoveltyDocuments', 'action' => 'delete', $novelty->id, $doc->id]),
            'showBadge'    => !$multipleStatuses,
            'badgeColors'  => $badgeColors,
            'statusLabels' => $statusLabels,
        ];
    }
    $noveltyDocGroups[] = [
        'label'    => $multipleStatuses ? ($statusLabels[$status] ?? $status) : null,
        'pillKind' => $multipleStatuses ? ($badgeColors[$status] ?? 'pill-muted') : null,
        'rows'     => $rows,
    ];
}
?>
<?= $this->element('documents_section', [
    'groups'        => $noveltyDocGroups,
    'totalDocs'     => $totalDocs,
    'canUpload'     => $showUploadSection,
    'uploadModalId' => 'uploadDocModal',
    'emptyTitle'    => 'Sin soportes adjuntos',
]) ?>

    </main>
</div><!-- /sgi-invoice-view-grid -->

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
                        <label class="input-label">Archivo</label>
                        <input type="file" name="file" class="form-control" required
                               accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                        <div class="form-text">Máximo <?= h(\App\Constants\UploadConstants::MAX_BYTES_LABEL) ?> — PDF, imágenes, Word o Excel.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-card" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Subir</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?= $this->element('document_row_template', ['showBadge' => true]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>
<?= $this->element('observations/drawer', [
    'observations'    => $novelty->novelty_observations ?? [],
    'count'           => count($novelty->novelty_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $novelty->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>

<?php $this->append('script') ?>
<script>
(function(){
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>
<?php $this->end() ?>

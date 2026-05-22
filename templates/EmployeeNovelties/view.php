<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmployeeNovelty $novelty
 * @var array $effectiveStatuses
 * @var array $documentsByStatus
 * @var bool $hasActiveTemplate
 * @var array $fieldLabels
 * @var string $currentStatus
 */
use App\Constants\NoveltyConstants;
use App\View\Presentation\NoveltyPresentation;

$this->assign('title', 'Novedad #' . $novelty->id);

$statusLabels = NoveltyConstants::STATUS_LABELS;
$statusIcons = NoveltyPresentation::STATUS_ICONS;
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
$isRejected = $novelty->isRejected();
$currentStatus = $novelty->pipeline_status;

$statusBadgeMap = [
    'aprobacion' => 'pill-warning-soft',
    'rrhh' => 'pill-info-soft',
    'contabilidad' => 'pill-primary-soft',
    'revision_firmas' => 'pill-warning-soft',
    'gdp' => 'pill-muted',
    'tesoreria' => 'pill-info-soft',
    'pagada' => 'pill-primary-soft',
    'rechazada' => 'pill-danger-soft',
];

// Documents prep
$totalDocs = array_sum(array_map('count', $documentsByStatus));
$badgeColors = NoveltyPresentation::STATUS_BADGES;
?>

<!-- Page header -->
<?php
$novViewId = $novelty->employee->full_name ?? ('Novedad #' . $novelty->id);
$novViewStatusLabel = $statusLabels[$novelty->pipeline_status] ?? $novelty->pipeline_status;
$novViewStatusPill = $statusBadgeMap[$novelty->pipeline_status] ?? 'pill-muted';
?>
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Novedades', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current"><?= h($novViewId) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title">Ver Novedad</span>
            <span class="sgi-edit-id-chip">#<?= h((string)$novelty->id) ?></span>
            <?php if ($isRejected): ?>
                <span class="pill pill-danger-soft">Rechazada</span>
            <?php else: ?>
                <span class="pill <?= h($novViewStatusPill) ?>"><?= h($novViewStatusLabel) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?php if (!empty($hasActiveTemplate)): ?>
        <?= $this->Html->link(
            '<i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>Exportar PDF',
            ['action' => 'exportPdf', $novelty->id],
            ['class' => 'btn btn-ghost-card sgi-fg-danger', 'escape' => false, 'target' => '_blank']
        ) ?>
        <?php endif; ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['employee_novelties']['can_edit'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $novelty->id],
            ['class' => 'btn btn-secondary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<?php
$noveltyPipelineLabels = $statusLabels;
$noveltyPipelineLabels[NoveltyConstants::STATUS_CONTABILIDAD] = 'Paso a Nómina';
$pipelineStepsToShow = $noveltyStatuses ?? $effectiveStatuses;
$isNovTerminal = $currentStatus === NoveltyConstants::STATUS_PAGADA;
$noveltyName = $novelty->custom_name ?: ($novelty->employee->full_name ?? ('Novedad #' . $novelty->id));
?>
<div class="sgi-invoice-view-grid view-anim">

    <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
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
            'idLabel'        => $noveltyName,
            'typeLabel'      => $novelty->novelty_type->name ?? null,
            'statusPill'     => $statusBadgeMap[$currentStatus] ?? 'pill-muted',
            'statusLabel'    => $statusLabels[$currentStatus] ?? ucfirst($currentStatus),
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

    <!-- ═══════════════════ CONTENIDO ═══════════════════ -->
    <main class="sgi-invoice-view-right">

    <!-- Información + Gestión -->
    <div class="card">
    <div class="row g-0">
        <div class="col-md-6" style="border-right:1px solid var(--rule);">
            <div class="sgi-section-head" style="padding:14px 18px 0;">
                <span class="sgi-label">Información de la Novedad</span>
            </div>
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

            <?php if (!empty($novelty->novelty_massive_employees)): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Empleados (Masiva)</span>
                <span class="sgi-data-value">
                    <?php foreach ($novelty->novelty_massive_employees as $me): ?>
                        <span class="pill pill-muted me-1 mb-1"><?= h($me->employee->full_name ?? '—') ?></span>
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
                    <span class="pill pill-<?= $novelty->is_paid ? 'primary-soft' : 'muted' ?>"><?= $novelty->is_paid ? 'Sí' : 'No' ?></span>
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
            <div class="sgi-section-head" style="padding:14px 18px 0;">
                <span class="sgi-label">Gestión</span>
            </div>
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
                    <span class="pill pill-<?= $novelty->passes_payroll ? 'primary-soft' : 'muted' ?>"><?= $novelty->passes_payroll ? 'Sí' : 'No' ?></span>
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
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Diligenciamiento</span>
                <span class="sgi-data-value"><?= $novelty->filing_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
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
    <div class="row g-0" style="border-bottom:1px solid var(--rule);">
        <div class="col-12">
            <div class="sgi-label">Firma del Funcionario</div>
            <div style="padding:.25rem 1.25rem .875rem;">
                <img src="<?= $this->Url->build('/' . $novelty->employee_signature) ?>" alt="Firma Funcionario"
                     style="max-width:400px;max-height:150px;background:var(--bg-subtle);padding:6px;">
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- General observations (legacy field) -->
    <?php if ($novelty->observations): ?>
    <div style="border-top:1px solid var(--rule);">
        <div class="sgi-section-head" style="padding:14px 18px 0;">
            <span class="sgi-label">Observaciones de Rechazo</span>
        </div>
        <div style="padding:.25rem 18px 14px;font-size:var(--fs-body);color:var(--text-muted);line-height:1.55;">
            <?= nl2br(h($novelty->observations)) ?>
        </div>
    </div>
    <?php endif; ?>
    </div><!-- /card Información + Gestión -->

<?php /* ── Soportes ──────────────────────────────────── */ ?>
<?php
$docGroups = [];
$multipleDocStatuses = count($documentsByStatus) > 1;
foreach ($documentsByStatus as $status => $docs) {
    $rows = [];
    foreach ($docs as $doc) {
        $rows[] = [
            'doc'          => $doc,
            'canDelete'    => false,
            'deleteUrl'    => null,
            'showBadge'    => !$multipleDocStatuses,
            'badgeColors'  => $badgeColors,
            'statusLabels' => $statusLabels,
        ];
    }
    $docGroups[] = [
        'label'    => $multipleDocStatuses ? ($statusLabels[$status] ?? $status) : null,
        'pillKind' => $multipleDocStatuses ? ($badgeColors[$status] ?? 'pill-muted') : null,
        'rows'     => $rows,
    ];
}
?>
<?= $this->element('documents_section', [
    'groups'        => $docGroups,
    'totalDocs'     => $totalDocs,
    'canUpload'     => false,
    'uploadModalId' => null,
    'emptyTitle'    => 'Sin soportes adjuntos',
]) ?>

<!-- Change History -->
<?php if (!empty($novelty->novelty_histories)): ?>
<div class="card" style="padding:18px 20px;">
    <div class="sgi-section-head" style="margin-bottom:12px;">
        <span class="sgi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            Historial de Cambios
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Campo</th>
                    <th>Valor Anterior</th>
                    <th>Valor Nuevo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($novelty->novelty_histories as $history): ?>
                <tr>
                    <td><?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?></td>
                    <td><?= $history->hasValue('user') ? h($history->user->full_name) : '' ?></td>
                    <td><?= h($fieldLabels[$history->field_changed] ?? $history->field_changed) ?></td>
                    <td class="text-muted"><?= h($history->old_value) ?: '—' ?></td>
                    <td class="fw-semibold"><?= h($history->new_value) ?: '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

    </main>
</div><!-- /sgi-invoice-view-grid -->
<?= $this->element('observations/drawer', [
    'observations'    => $novelty->novelty_observations ?? [],
    'count'           => count($novelty->novelty_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $novelty->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>

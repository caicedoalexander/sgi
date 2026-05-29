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
            <div class="field-row">
                <span class="k">Empleado</span>
                <span class="v"><?= h($novelty->employee->full_name ?? '—') ?></span>
            </div>
            <?php elseif ($novelty->custom_name): ?>
            <div class="field-row">
                <span class="k">Nombre</span>
                <span class="v"><?= h($novelty->custom_name) ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($novelty->novelty_massive_employees)): ?>
            <div class="field-row">
                <span class="k">Empleados (Masiva)</span>
                <span class="v">
                    <?php foreach ($novelty->novelty_massive_employees as $me): ?>
                        <span class="pill pill-muted me-1 mb-1"><?= h($me->employee->full_name ?? '—') ?></span>
                    <?php endforeach; ?>
                </span>
            </div>
            <?php endif; ?>

            <div class="field-row">
                <span class="k">Tipo</span>
                <span class="v"><?= h($novelty->novelty_type->name ?? '—') ?></span>
            </div>
            <?php if ($novelty->permission_date): ?>
            <div class="field-row">
                <span class="k">Fecha del Permiso</span>
                <span class="v"><?= $novelty->permission_date->format('d/m/Y') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type): ?>
            <div class="field-row">
                <span class="k">Horario</span>
                <span class="v"><?= $scheduleLabels[$novelty->schedule_type] ?? h($novelty->schedule_type) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_DAYS): ?>
            <div class="field-row">
                <span class="k">Fecha Inicio</span>
                <span class="v"><?= $novelty->start_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <div class="field-row">
                <span class="k">Fecha Fin</span>
                <span class="v"><?= $novelty->end_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_HOURS): ?>
            <div class="field-row">
                <span class="k">Hora Salida</span>
                <span class="v"><?= h($novelty->start_time) ?: '—' ?></span>
            </div>
            <div class="field-row">
                <span class="k">Hora Entrada</span>
                <span class="v"><?= h($novelty->end_time) ?: '—' ?></span>
            </div>
            <?php endif; ?>
            <div class="field-row">
                <span class="k">Remunerado</span>
                <span class="v">
                    <span class="pill pill-<?= $novelty->is_paid ? 'primary-soft' : 'muted' ?>"><?= $novelty->is_paid ? 'Sí' : 'No' ?></span>
                </span>
            </div>
            <?php if ($novelty->reason): ?>
            <div class="field-row">
                <span class="k">Motivo</span>
                <span class="v"><?= nl2br(h($novelty->reason)) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <div class="sgi-section-head" style="padding:14px 18px 0;">
                <span class="sgi-label">Gestión</span>
            </div>
            <div class="field-row">
                <span class="k">Registrado por</span>
                <span class="v"><?= h($novelty->registered_by_user->full_name ?? '—') ?></span>
            </div>
            <?php if ($novelty->rrhh_by_user): ?>
            <div class="field-row">
                <span class="k">Procesado RRHH por</span>
                <span class="v"><?= h($novelty->rrhh_by_user->full_name ?? '—') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->passes_payroll !== null): ?>
            <div class="field-row">
                <span class="k">Pasa a Nómina</span>
                <span class="v">
                    <span class="pill pill-<?= $novelty->passes_payroll ? 'primary-soft' : 'muted' ?>"><?= $novelty->passes_payroll ? 'Sí' : 'No' ?></span>
                </span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->approved_by_user): ?>
            <div class="field-row">
                <span class="k">Aprobado/Rechazado por</span>
                <span class="v"><?= h($novelty->approved_by_user->full_name ?? '—') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->approved_at): ?>
            <div class="field-row">
                <span class="k">Fecha</span>
                <span class="v"><?= $novelty->approved_at->format('d/m/Y H:i') ?></span>
            </div>
            <?php endif; ?>
            <div class="field-row">
                <span class="k">Fecha Diligenciamiento</span>
                <span class="v"><?= $novelty->filing_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
            <?php if ($novelty->isGrouped()): ?>
            <div class="field-row">
                <span class="k">Documento de Liquidación</span>
                <span class="v">
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
<?php
$histCount = count($novelty->novelty_histories);
$initialsOf = static function (?string $name): string {
    if (!$name) {
        return '?';
    }
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }

    return $ini ?: mb_strtoupper(mb_substr($name, 0, 2));
};
?>
<div class="sgi-card">
    <div class="d-flex justify-content-between align-items-center" style="margin-bottom:14px;">
        <span class="sgi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            Historial de Cambios
            <span class="sgi-folder-count"><?= $histCount ?></span>
        </span>
    </div>
    <div class="col-flex">
    <?php foreach ($novelty->novelty_histories as $hi => $history):
        $hUser = $history->hasValue('user') ? $history->user->full_name : '—';
        $hField = $fieldLabels[$history->field_changed] ?? $history->field_changed;
    ?>
        <div class="d-flex align-items-center" style="gap:12px;padding:10px 0;<?= $hi === 0 ? '' : 'border-top:1px solid var(--rule);' ?>font-size:var(--fs-body-sm);">
            <span class="mono" style="color:var(--text-muted);flex-shrink:0;min-width:110px;">
                <?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?>
            </span>
            <span class="d-inline-flex align-items-center" style="gap:6px;flex-shrink:0;min-width:140px;">
                <span class="av av-sm"><?= h($initialsOf($hUser)) ?></span>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($hUser) ?></span>
            </span>
            <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($hField) ?>
            </span>
            <span style="color:var(--text-muted);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $history->old_value ? h($history->old_value) : '—' ?>
            </span>
            <i class="bi bi-arrow-right" aria-hidden="true" style="color:var(--text-faint);font-size:11px;flex-shrink:0;"></i>
            <span style="color:var(--primary-color);font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $history->new_value ? h($history->new_value) : '—' ?>
            </span>
        </div>
    <?php endforeach; ?>
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

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
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Ver Novedad</span>
    <div class="d-flex gap-2">
        <?php if (!empty($hasActiveTemplate)): ?>
        <?= $this->Html->link(
            '<i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Exportar PDF',
            ['action' => 'exportPdf', $novelty->id],
            ['class' => 'btn btn-outline-danger btn-sm', 'escape' => false, 'target' => '_blank']
        ) ?>
        <?php endif; ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['employee_novelties']['can_edit'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $novelty->id],
            ['class' => 'btn btn-warning btn-sm', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Main card -->
<div class="card card-primary mb-4">

    <!-- Header -->
    <div class="card-header d-flex align-items-start justify-content-between gap-3" style="padding:1rem 1.25rem;">
        <div class="d-flex align-items-start gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:52px;height:52px;background:var(--primary-color);color:#fff;font-size:1.35rem;">
                <i class="bi bi-calendar-check" aria-hidden="true"></i>
            </div>
            <div>
                <div style="font-size:1.25rem;font-weight:700;letter-spacing:-.03em;color:#111;line-height:1.15;">
                    <?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?>
                </div>
                <div class="mt-1 d-flex align-items-center gap-2 flex-wrap">
                    <span class="pill pill-secondary-soft"><?= h($novelty->novelty_type->name ?? '') ?></span>
                    <span class="pill <?= $statusBadgeMap[$currentStatus] ?? 'pill-muted' ?>">
                        <?= $statusLabels[$currentStatus] ?? ucfirst($currentStatus) ?>
                    </span>
                    <?php if ($isRejected): ?>
                        <span class="pill pill-danger-soft">Rechazada</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($novelty->novelty_massive_employees)): ?>
                <div class="mt-1" style="font-size:.8rem;color:#777;font-weight:500;">
                    Masiva: <?= count($novelty->novelty_massive_employees) ?> empleados
                </div>
                <?php endif; ?>
            </div>
        </div>
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

    <!-- Two-column data: Información | Gestión -->
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-label">Información de la Novedad</div>
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
            <div class="sgi-label">Gestión</div>
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
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <div class="col-12">
            <div class="sgi-label">Firma del Funcionario</div>
            <div style="padding:.25rem 1.25rem .875rem;">
                <img src="<?= $this->Url->build('/' . $novelty->employee_signature) ?>" alt="Firma Funcionario"
                     style="max-width:400px;max-height:150px;border:1px solid var(--border-color);">
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Observations (read-only, like invoices/view) -->
    <?php if (!empty($novelty->novelty_observations)): ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-label">Observaciones</div>
        <div style="padding:.5rem 1.25rem .875rem;max-height:400px;overflow-y:auto;">
            <?php foreach ($novelty->novelty_observations as $obs): ?>
            <div class="d-flex align-items-start gap-2 mb-3">
                <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:32px;height:32px;background:var(--primary-color);color:#fff;font-size:.7rem;font-weight:700;">
                    <?php
                    $names = explode(' ', $obs->user->full_name ?? '');
                    echo strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[1] ?? '', 0, 1));
                    ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="d-flex align-items-center gap-2">
                        <span style="font-size:.8rem;font-weight:600;color:#222;">
                            <?= h($obs->user->full_name ?? '') ?>
                        </span>
                        <span style="font-size:.7rem;color:#aaa;">
                            <?= $obs->created ? $obs->created->format('d/m/Y H:i') : '' ?>
                        </span>
                    </div>
                    <div style="font-size:.84rem;color:#444;line-height:1.5;margin-top:.15rem;">
                        <?= nl2br(h($obs->message)) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- General observations (legacy field) -->
    <?php if ($novelty->observations): ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-label">Observaciones de Rechazo</div>
        <div style="padding:.25rem 1.25rem .875rem;font-size:.875rem;color:#555;line-height:1.65;">
            <?= nl2br(h($novelty->observations)) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Contact bar -->
    <div class="sgi-contact-bar">
        <?php if ($novelty->registered_by_user): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-person" aria-hidden="true"></i>
            <span>Registrado por <?= h($novelty->registered_by_user->full_name) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($novelty->created): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
            <span>Creado: <?= $novelty->created->format('d/m/Y') ?></span>
        </div>
        <?php endif; ?>
        <?php if ($novelty->modified): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-pencil-square" aria-hidden="true"></i>
            <span>Modificado: <?= $novelty->modified->format('d/m/Y') ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Documents (read-only, grid layout like invoices/view) -->
<div class="card card-primary mb-4">
    <div class="card-header">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" aria-hidden="true"></i>
            Soportes
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
    </div>

    <?php if (empty($documentsByStatus)): ?>
        <div class="p-3 text-center text-muted" style="font-size:.875rem">
            <i class="bi bi-file-earmark-x me-1" aria-hidden="true"></i>Sin soportes adjuntos
        </div>
    <?php else: ?>
        <div class="p-3">
            <div class="row row-cols-1 row-cols-md-3 g-3">
                <?php foreach ($documentsByStatus as $status => $docs): ?>
                    <?php foreach ($docs as $doc): ?>
                    <div class="col">
                        <div style="border:1px solid var(--border-color);height:100%;display:flex;flex-direction:column;">
                            <div style="padding:.6rem .875rem;border-bottom:1px solid var(--border-color);background:var(--bg-muted);display:flex;align-items:center;gap:.5rem;min-width:0;">
                                <i class="bi <?= h($this->DocumentIcon->iconClass($doc->mime_type)) ?> flex-shrink-0"
                                   style="color:<?= h($this->DocumentIcon->iconColor($doc->mime_type)) ?>;font-size:1.1rem;"></i>
                                <div style="min-width:0;flex:1;overflow:hidden;">
                                    <span style="font-size:.78rem;font-weight:600;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;" title="<?= h($doc->file_name) ?>">
                                        <?= h($doc->file_name) ?>
                                    </span>
                                </div>
                            </div>
                            <div style="padding:.6rem .875rem;flex:1;font-size:.78rem;color:#555;display:flex;flex-direction:column;gap:.3rem;">
                                <div>
                                    <span class="pill <?= $badgeColors[$status] ?? 'pill-muted' ?>" style="font-size:.65rem;">
                                        <?= $statusLabels[$status] ?? $status ?>
                                    </span>
                                </div>
                                <div style="display:flex;align-items:center;gap:.35rem;color:#666;">
                                    <i class="bi bi-person" style="font-size:.8rem;" aria-hidden="true"></i>
                                    <span><?= $doc->has('uploaded_by_user') ? h($doc->uploaded_by_user->full_name) : '—' ?></span>
                                </div>
                                <div style="display:flex;align-items:center;gap:.35rem;color:#888;">
                                    <i class="bi bi-clock" style="font-size:.75rem;" aria-hidden="true"></i>
                                    <span><?= $doc->created?->format('d/m/Y H:i') ?></span>
                                </div>
                                <?php if ($doc->file_size): ?>
                                <div style="color:#aaa;font-size:.72rem;"><?= $this->Number->toReadableSize($doc->file_size) ?></div>
                                <?php endif; ?>
                            </div>
                            <div style="padding:.5rem .875rem;border-top:1px solid var(--border-color);text-align:right;">
                                <?= $this->Html->link(
                                    '<i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Abrir',
                                    '/' . $doc->file_path,
                                    ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank']
                                ) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Change History -->
<?php if (!empty($novelty->novelty_histories)): ?>
<div class="card">
    <div class="card-header">Historial de Cambios</div>
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

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\NoveltyLiquidationDoc $doc
 * @var array $groupErrors
 * @var array $effectiveStatuses
 * @var array $documentsByStatus
 * @var object|null $liquidationDocument
 * @var array $groupHistories
 * @var array $fieldLabels
 * @var string $currentStatus
 */
use App\Constants\NoveltyConstants;
use App\View\Presentation\NoveltyPresentation;

$this->assign('title', 'Liquidación: ' . h($doc->liquidation_number));

$statusLabels  = NoveltyConstants::STATUS_LABELS;
$periodLabels  = NoveltyConstants::PERIOD_LABELS;
$signerLabels  = NoveltyConstants::SIGNER_LABELS;
$paymentLabels = NoveltyConstants::PAYMENT_LABELS;
$isRejected    = $doc->pipeline_status === NoveltyConstants::STATUS_RECHAZADA;
$isPaid        = $doc->pipeline_status === NoveltyConstants::STATUS_PAGADA;
$currentStatus = $doc->pipeline_status;

$statusBadgeMap = NoveltyPresentation::STATUS_BADGES + [
    NoveltyConstants::STATUS_RECHAZADA => 'pill-danger-soft',
];
$nldStatusPill  = $statusBadgeMap[$currentStatus] ?? 'pill-muted';
$nldStatusLabel = $statusLabels[$currentStatus] ?? ucfirst($currentStatus);
$badgeColors   = NoveltyPresentation::STATUS_BADGES;
$totalDocs     = array_sum(array_map('count', $documentsByStatus));
$noveltyCount  = count($doc->employee_novelties ?? []);
?>

<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Liquidaciones', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current"><?= h($doc->liquidation_number) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title">Ver Liquidación</span>
            <span class="sgi-edit-id-chip"><?= h($doc->liquidation_number) ?></span>
            <?php if ($isRejected): ?>
                <span class="pill pill-danger-soft">Rechazada</span>
            <?php else: ?>
                <span class="pill <?= $nldStatusPill ?>"><?= h($nldStatusLabel) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['novelty_liquidation_docs']['can_edit'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $doc->id],
            ['class' => 'btn btn-secondary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="sgi-invoice-view-grid view-anim">

    <!-- ═════════════════════ SIDEBAR ═════════════════════ -->
    <aside class="sgi-invoice-view-left">
        <?php
        $registryLines = [];
        if ($doc->performed_by_user) {
            $registryLines[] = ['icon' => 'bi-person', 'html' => 'Elaborado por ' . h($doc->performed_by_user->full_name)];
        }
        if ($doc->created) {
            $registryLines[] = ['icon' => 'bi-calendar3', 'html' => 'Creado · <span class="mono">' . $doc->created->format('d/m/Y') . '</span>'];
        }
        if ($doc->modified) {
            $registryLines[] = ['icon' => 'bi-pencil-square', 'html' => 'Modificado · <span class="mono">' . $doc->modified->format('d/m/Y') . '</span>'];
        }
        if ($doc->payment_date) {
            $registryLines[] = ['icon' => 'bi-cash-coin', 'html' => 'Pagado · <span class="mono">' . $doc->payment_date->format('d/m/Y') . '</span>'];
        }

        $extraPills = [];
        if ($doc->passes_for_payment === true) {
            $extraPills[] = '<span class="pill pill-primary-soft">Pasa a pago</span>';
        } elseif ($doc->passes_for_payment === false) {
            $extraPills[] = '<span class="pill pill-muted">No pasa a pago</span>';
        }
        if ($doc->payment_status) {
            $extraPills[] = '<span class="pill pill-info-soft">' . h($paymentLabels[$doc->payment_status] ?? $doc->payment_status) . '</span>';
        }
        $extraPillHtml = implode(' ', $extraPills);

        echo $this->element('pipeline_sidebar', [
            'icon'           => 'file-earmark-text',
            'idLabel'        => $doc->liquidation_number,
            'typeLabel'      => 'Liquidación',
            'statusPill'     => $nldStatusPill,
            'statusLabel'    => $nldStatusLabel,
            'isRejected'     => $isRejected,
            'extraPillHtml'  => $extraPillHtml,
            'entityLabel'    => 'Período',
            'entityValue'    => $periodLabels[$doc->period] ?? $doc->period,
            'entitySubLabel' => $noveltyCount . ' novedad' . ($noveltyCount !== 1 ? 'es' : ''),
            'entitySubIcon'  => 'bi-people',
            'amountLabel'    => null,
            'amount'         => null,
            'pipelineSteps'  => $effectiveStatuses,
            'pipelineLabels' => $statusLabels,
            'currentStatus'  => $currentStatus,
            'isTerminal'     => $isPaid,
            'modifiedAt'     => $doc->modified ?? null,
            'registryLines'  => $registryLines,
        ]);
        ?>
    </aside>

    <!-- ═════════════════════ CONTENIDO ═════════════════════ -->
    <main class="sgi-invoice-view-right">

        <!-- Información + Novedades -->
        <div class="card">
            <div class="row g-0">
                <div class="col-md-6" style="border-right:1px solid var(--rule);">
                    <div class="sgi-section-head" style="padding:14px 18px 0;">
                        <span class="sgi-label">Información del Documento</span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">No. Liquidación</span>
                        <span class="sgi-data-value mono"><?= h($doc->liquidation_number) ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Período</span>
                        <span class="sgi-data-value"><?= h($periodLabels[$doc->period] ?? $doc->period) ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Fecha Documento</span>
                        <span class="sgi-data-value mono"><?= $doc->document_date?->format('d/m/Y') ?: '—' ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Elaborado por</span>
                        <span class="sgi-data-value"><?= h($doc->performed_by_user->full_name ?? '—') ?></span>
                    </div>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Creado por</span>
                        <span class="sgi-data-value"><?= h($doc->created_by_user->full_name ?? '—') ?></span>
                    </div>
                    <?php if ($doc->passes_for_payment !== null): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Pasa para Pago</span>
                        <span class="sgi-data-value">
                            <span class="pill pill-<?= $doc->passes_for_payment ? 'primary-soft' : 'muted' ?>"><?= $doc->passes_for_payment ? 'Sí' : 'No' ?></span>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if ($doc->payment_status): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Estado de Pago</span>
                        <span class="sgi-data-value"><?= h($paymentLabels[$doc->payment_status] ?? $doc->payment_status) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($doc->payment_date): ?>
                    <div class="sgi-data-row">
                        <span class="sgi-data-label">Fecha de Pago</span>
                        <span class="sgi-data-value mono"><?= $doc->payment_date->format('d/m/Y') ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <div class="sgi-section-head" style="padding:14px 18px 0;">
                        <span class="sgi-label d-inline-flex align-items-center gap-2">
                            Novedades Asociadas
                            <span class="sgi-folder-count"><?= $noveltyCount ?></span>
                        </span>
                    </div>
                    <?php if ($noveltyCount > 0): ?>
                    <?php $nvGrid = 'display:grid;grid-template-columns:1.5fr 1fr;gap:10px;align-items:center;'; ?>
                    <div style="padding:0 18px 14px;">
                        <div style="max-height:280px;overflow-y:auto;">
                            <div style="<?= $nvGrid ?>padding:8px 12px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.6px;text-transform:uppercase;" role="row">
                                <span>Empleado</span>
                                <span>Tipo</span>
                            </div>
                            <?php foreach ($doc->employee_novelties as $nvIdx => $novelty): ?>
                            <div class="clickable-row" role="row"
                                 data-href="<?= $this->Url->build(['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id]) ?>"
                                 style="<?= $nvGrid ?>padding:10px 12px;background:#fff;cursor:pointer;<?= $nvIdx > 0 ? 'border-top:1px solid var(--rule);' : '' ?>">
                                <span style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?>
                                </span>
                                <span style="font-size:11.5px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= h($novelty->novelty_type->name ?? '—') ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="padding:.5rem 18px 1rem;color:var(--text-disabled);font-size:var(--fs-body-sm);">
                        No hay novedades asociadas.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Firmas (read-only) -->
        <?php if (!empty($doc->novelty_liquidation_signatures)): ?>
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-pen" aria-hidden="true"></i>Firmas
                </span>
            </div>
            <div class="row g-3">
                <?php foreach ($doc->novelty_liquidation_signatures as $sig): ?>
                <div class="col-md-6 col-lg-3">
                    <div style="background:var(--bg-subtle);padding:14px;text-align:center;height:100%;">
                        <div class="sgi-label" style="margin-bottom:6px;"><?= $signerLabels[$sig->signer_type] ?? h($sig->signer_type) ?></div>
                        <?php if ($sig->signature_path): ?>
                            <img src="<?= $this->Url->build('/' . $sig->signature_path) ?>" alt="Firma"
                                 style="max-width:100%;max-height:90px;">
                            <div class="mt-2" style="font-size:var(--fs-body-sm);color:var(--text-muted);line-height:1.4;">
                                <?= h($sig->signed_by_user->full_name ?? '') ?>
                                <?php if ($sig->approved_at): ?>
                                <div class="mono" style="font-size:var(--fs-meta);"><?= $sig->approved_at->format('d/m/Y H:i') ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="pill pill-primary-soft mt-2">Firmado</span>
                        <?php else: ?>
                            <span class="pill pill-warning-soft mt-2">Pendiente</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payments (read-only) -->
        <?php if (!empty($doc->liquidation_doc_payments)): ?>
        <?php $payGrid = 'display:grid;grid-template-columns:1.4fr 1fr 0.9fr 1.5fr 1.2fr;gap:12px;align-items:center;'; ?>
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-bank" aria-hidden="true"></i>Pagos Registrados
                </span>
            </div>
            <div class="sgi-card" style="padding:0;">
                <div style="<?= $payGrid ?>padding:9px 14px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.6px;text-transform:uppercase;" role="row">
                    <span>Entidad Bancaria</span>
                    <span style="text-align:right;">Monto</span>
                    <span>Fecha</span>
                    <span>Estado</span>
                    <span>Registrado por</span>
                </div>
                <?php foreach ($doc->liquidation_doc_payments as $payIdx => $payment): ?>
                <div role="row"
                     style="<?= $payGrid ?>padding:11px 14px;background:#fff;<?= $payIdx > 0 ? 'border-top:1px solid var(--rule);' : '' ?>">
                    <span style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($payment->banking_entity->name ?? '—') ?>
                    </span>
                    <span class="mono" style="text-align:right;font-size:12.5px;font-weight:700;color:var(--text-default);">
                        $ <?= number_format((float)$payment->amount, 0, ',', '.') ?>
                    </span>
                    <span class="mono" style="font-size:11.5px;color:var(--text-muted);">
                        <?= $payment->payment_date?->format('d/m/Y') ?? '—' ?>
                    </span>
                    <span style="min-width:0;">
                        <?php if ($payment->authorized): ?>
                            <span class="pill pill-primary-soft pill-sm"><i class="bi bi-check-circle" aria-hidden="true"></i>Autorizado</span>
                            <?php if ($payment->authorized_by_user): ?>
                            <div style="font-size:var(--fs-meta);color:var(--text-muted);margin-top:2px;">
                                <?= h($payment->authorized_by_user->full_name ?? $payment->authorized_by_user->username ?? '') ?><?php if ($payment->authorized_date): ?> · <span class="mono"><?= $payment->authorized_date->format('d/m/Y') ?></span><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="pill pill-warning-soft pill-sm"><i class="bi bi-clock" aria-hidden="true"></i>Pendiente</span>
                        <?php endif; ?>
                    </span>
                    <span style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($payment->created_by_user->full_name ?? $payment->created_by_user->username ?? '—') ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Soportes + Observaciones (grid lateral) -->
        <div class="sgi-edit-side-grid">

            <!-- Soportes -->
            <div class="card" style="padding:18px 20px;">
                <div class="sgi-section-head" style="margin-bottom:12px;">
                    <span class="sgi-label d-inline-flex align-items-center gap-2">
                        <i class="bi bi-paperclip" aria-hidden="true"></i>
                        Soportes
                        <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
                    </span>
                </div>

                <!-- Documento de Liquidación -->
                <div style="padding:.3rem .6rem;background:var(--primary-soft);display:flex;align-items:center;gap:.4rem;margin-bottom:8px;">
                    <span class="pill pill-primary">D. Liquidación</span>
                </div>
                <?php if ($liquidationDocument ?? null): ?>
                <div style="display:flex;align-items:center;gap:.75rem;padding:.8rem .75rem;background:var(--primary-soft);margin-bottom:10px;">
                    <div style="width:34px;height:34px;flex-shrink:0;background:#fff;display:flex;align-items:center;justify-content:center;">
                        <i class="bi <?= h($this->DocumentIcon->iconClass($liquidationDocument->mime_type)) ?>"
                           style="color:<?= h($this->DocumentIcon->iconColor($liquidationDocument->mime_type)) ?>;font-size:1rem;"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"
                             title="<?= h($liquidationDocument->file_name) ?>">
                            <?= h($liquidationDocument->file_name) ?>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
                            <span style="font-size:var(--fs-label);color:var(--text-disabled);">
                                <i class="bi bi-clock" style="font-size:var(--fs-micro);" aria-hidden="true"></i>
                                <?= $liquidationDocument->created?->format('d/m/Y H:i') ?>
                            </span>
                            <?php if ($liquidationDocument->file_size): ?>
                            <span style="font-size:var(--fs-meta);color:var(--text-disabled);"><?= $this->Number->toReadableSize($liquidationDocument->file_size) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?= $this->Html->link(
                        '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>',
                        '/' . $liquidationDocument->file_path,
                        ['class' => 'btn btn-sm btn-ghost-card', 'style' => 'padding:.25rem .45rem;line-height:1;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
                    ) ?>
                </div>
                <?php else: ?>
                <div style="display:flex;align-items:center;gap:.6rem;padding:.6rem .75rem;background:var(--bg-muted);margin-bottom:10px;">
                    <i class="bi bi-file-earmark-x" style="color:var(--text-disabled);" aria-hidden="true"></i>
                    <span style="font-size:var(--fs-body-sm);color:var(--text-disabled);">Sin documento</span>
                </div>
                <?php endif; ?>

                <!-- Lista de soportes -->
                <?php if (empty($documentsByStatus)): ?>
                <div class="sgi-dropzone-empty">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                    <div>Sin soportes adjuntos</div>
                </div>
                <?php else: ?>
                <div style="max-height:420px;overflow-y:auto;">
                    <?php
                    $multipleStatuses = count($documentsByStatus) > 1;
                    foreach ($documentsByStatus as $status => $docs):
                    ?>
                    <?php if ($multipleStatuses): ?>
                    <div style="padding:.3rem .6rem;background:var(--bg-subtle);display:flex;align-items:center;gap:.4rem;">
                        <span class="pill <?= $badgeColors[$status] ?? 'pill-muted' ?>"><?= $statusLabels[$status] ?? $status ?></span>
                        <span style="font-size:var(--fs-label);color:var(--text-disabled);"><?= count($docs) ?> archivo<?= count($docs) !== 1 ? 's' : '' ?></span>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($docs as $docFile): ?>
                        <?= $this->element('document_row', [
                            'doc'          => $docFile,
                            'canDelete'    => false,
                            'deleteUrl'    => null,
                            'showBadge'    => !$multipleStatuses,
                            'badgeColors'  => $badgeColors,
                            'statusLabels' => $statusLabels,
                        ]) ?>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Observaciones -->
            <?php $obsList = $doc->novelty_observations ?? []; ?>
            <div class="card sgi-obs-card" style="padding:18px 20px;">
                <div class="sgi-section-head" style="margin-bottom:12px;">
                    <span class="sgi-label d-inline-flex align-items-center gap-2">
                        <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                        Observaciones
                        <span class="sgi-folder-count"><?= count($obsList) ?></span>
                    </span>
                </div>

                <?php if (empty($obsList)): ?>
                <div class="sgi-obs-empty">
                    <i class="bi bi-chat-square-dots" aria-hidden="true" style="font-size:1.5rem;"></i>
                    <span style="font-size:var(--fs-body-sm);">Sin observaciones</span>
                </div>
                <?php else: ?>
                <div class="sgi-obs-list" style="max-height:400px;">
                    <?php foreach ($obsList as $obs): ?>
                        <?= $this->element('observation_bubble', [
                            'observation' => $obs,
                            'isMine' => false,
                        ]) ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /sgi-edit-side-grid -->

        <!-- Historial de Cambios del Grupo -->
        <?php if (!empty($groupHistories)): ?>
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    Historial de Cambios del Grupo
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Novedad</th>
                            <th>Usuario</th>
                            <th>Campo</th>
                            <th>Valor Anterior</th>
                            <th>Valor Nuevo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groupHistories as $history): ?>
                        <tr>
                            <td class="mono"><?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?></td>
                            <td style="font-size:var(--fs-body-lg);">
                                <?php if ($history->has('employee_novelty')): ?>
                                    <?= $this->Html->link(
                                        '#' . $history->employee_novelty->id,
                                        ['controller' => 'EmployeeNovelties', 'action' => 'view', $history->employee_novelty->id],
                                        ['class' => 'mono']
                                    ) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= $history->hasValue('user') ? h($history->user->full_name) : '' ?></td>
                            <td><?= h($fieldLabels[$history->field_changed] ?? $history->field_changed) ?></td>
                            <td class="sgi-fg-muted"><?= h($history->old_value) ?: '—' ?></td>
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

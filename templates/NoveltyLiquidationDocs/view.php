<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\NoveltyLiquidationDocViewViewModel $viewModel
 * @var array $groupErrors
 * @var array $effectiveStatuses
 * @var array $documentsByStatus
 * @var object|null $liquidationDocument
 * @var array $groupHistories
 * @var array $fieldLabels
 */
use App\Constants\NoveltyConstants;
use App\View\Presentation\NoveltyPresentation;

$doc = $viewModel->record;
$this->assign('title', $viewModel->pageTitle);

$statusLabels  = NoveltyConstants::STATUS_LABELS;
$periodLabels  = NoveltyConstants::PERIOD_LABELS;
$signerLabels  = NoveltyConstants::SIGNER_LABELS;
$paymentLabels = NoveltyConstants::PAYMENT_LABELS;
$isRejected    = $viewModel->isRejected;
$isPaid        = $viewModel->isTerminal;
$currentStatus = $viewModel->currentStatus;

[$nldStatusLabel, $nldStatusPill] = $viewModel->currentStatusBadge;
$badgeColors   = NoveltyPresentation::STATUS_BADGES;
$totalDocs     = array_sum(array_map('count', $documentsByStatus));
$noveltyCount  = $viewModel->noveltyCount;
?>

<!-- Page header -->
<div class="spi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="spi-breadcrumb">
            <?= $this->Html->link('Liquidaciones', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current"><?= h($doc->liquidation_number) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="spi-page-title">Ver Liquidación</span>
            <span class="spi-edit-id-chip"><?= h($doc->liquidation_number) ?></span>
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
            ['class' => 'btn btn-default', 'escape' => false]
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

<div class="spi-invoice-view-grid view-anim">

    <!-- ═════════════════════ SIDEBAR ═════════════════════ -->
    <aside class="spi-invoice-view-left">
        <?php
        $registryLines = $viewModel->registryLines;
        $extraPillHtml = $viewModel->extraPillHtml;

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
    <main class="spi-invoice-view-right">

        <!-- Información + Novedades -->
        <div class="spi-card">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
                <div>
                    <div class="spi-label" style="margin-bottom:6px;">Información del Documento</div>
                    <div class="field-row">
                        <span class="k">No. Liquidación</span>
                        <span class="v mono"><?= h($doc->liquidation_number) ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Período</span>
                        <span class="v"><?= h($periodLabels[$doc->period] ?? $doc->period) ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Fecha Documento</span>
                        <span class="v mono"><?= $doc->document_date?->format('d/m/Y') ?: '—' ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Elaborado por</span>
                        <span class="v"><?= h($doc->performed_by_user->full_name ?? '—') ?></span>
                    </div>
                    <div class="field-row">
                        <span class="k">Creado por</span>
                        <span class="v"><?= h($doc->created_by_user->full_name ?? '—') ?></span>
                    </div>
                    <?php if ($doc->passes_for_payment !== null): ?>
                    <div class="field-row">
                        <span class="k">Pasa para Pago</span>
                        <span class="v">
                            <span class="pill pill-<?= $doc->passes_for_payment ? 'primary-soft' : 'muted' ?>"><?= $doc->passes_for_payment ? 'Sí' : 'No' ?></span>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if ($doc->payment_status): ?>
                    <div class="field-row">
                        <span class="k">Estado de Pago</span>
                        <span class="v"><?= h($paymentLabels[$doc->payment_status] ?? $doc->payment_status) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($doc->payment_date): ?>
                    <div class="field-row">
                        <span class="k">Fecha de Pago</span>
                        <span class="v mono"><?= $doc->payment_date->format('d/m/Y') ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="spi-label d-inline-flex align-items-center gap-2" style="margin-bottom:6px;">
                        Novedades Asociadas
                        <span class="spi-folder-count"><?= $noveltyCount ?></span>
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
            <div class="spi-section-head" style="margin-bottom:12px;">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-pen" aria-hidden="true"></i>Firmas
                </span>
            </div>
            <div class="row g-3">
                <?php foreach ($doc->novelty_liquidation_signatures as $sig): ?>
                <div class="col-md-6 col-lg-3">
                    <div style="background:var(--bg-subtle);padding:14px;text-align:center;height:100%;">
                        <div class="spi-label" style="margin-bottom:6px;"><?= $signerLabels[$sig->signer_type] ?? h($sig->signer_type) ?></div>
                        <?php if ($sig->signature_path): ?>
                            <span class="pill pill-primary-soft"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Firmado</span>
                            <div class="mt-2" style="font-size:var(--fs-body-sm);color:var(--text-muted);line-height:1.4;">
                                <?= h($sig->signed_by_user->full_name ?? '') ?>
                                <?php if ($sig->approved_at): ?>
                                <div class="mono" style="font-size:var(--fs-meta);"><?= $sig->approved_at->format('d/m/Y H:i') ?></div>
                                <?php endif; ?>
                            </div>
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
            <div class="spi-section-head" style="margin-bottom:12px;">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-bank" aria-hidden="true"></i>Pagos Registrados
                </span>
            </div>
            <div class="spi-card" style="padding:0;">
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
                        <?php $pStatus = $payment->status ?? ($payment->authorized ? 'authorized' : 'pending'); ?>
                        <?php if ($pStatus === 'authorized'): ?>
                            <span class="pill pill-primary-soft pill-sm"><i class="bi bi-check-circle" aria-hidden="true"></i>Autorizado</span>
                            <?php if ($payment->authorized_by_user): ?>
                            <div style="font-size:var(--fs-meta);color:var(--text-muted);margin-top:2px;">
                                <?= h($payment->authorized_by_user->full_name ?? $payment->authorized_by_user->username ?? '') ?><?php if ($payment->authorized_date): ?> · <span class="mono"><?= $payment->authorized_date->format('d/m/Y') ?></span><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php elseif ($pStatus === 'rejected'): ?>
                            <span class="pill pill-danger-soft pill-sm"><i class="bi bi-x-circle" aria-hidden="true"></i>Rechazado</span>
                            <?php if ($payment->rejection_reason): ?>
                            <div style="font-size:var(--fs-meta);color:var(--text-muted);margin-top:2px;">
                                <?= h($payment->rejection_reason) ?>
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

        <!-- Documento de Liquidación (destacado) -->
        <div class="spi-card d-flex flex-column">
            <div class="d-flex align-items-center" style="margin-bottom:12px;">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    Documento de Liquidación
                </span>
            </div>
            <?php if ($liquidationDocument ?? null): ?>
            <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);">
                <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                    <i class="bi <?= h($this->DocumentIcon->iconClass($liquidationDocument->mime_type)) ?>"
                       style="color:<?= h($this->DocumentIcon->iconColor($liquidationDocument->mime_type)) ?>;font-size:18px;" aria-hidden="true"></i>
                </div>
                <div class="grow">
                    <div title="<?= h($liquidationDocument->file_name) ?>"
                         style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($liquidationDocument->file_name) ?>
                    </div>
                    <div class="row-flex gap-6 mono spi-body-faint" style="margin-top:2px;">
                        <span><?= $liquidationDocument->created?->format('d/m/Y H:i') ?></span>
                        <?php if ($liquidationDocument->file_size): ?>
                        <span>· <?= $this->Number->toReadableSize($liquidationDocument->file_size) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row-flex gap-4" style="flex-shrink:0;">
                    <?= $this->Html->link(
                        '<i class="bi bi-eye" aria-hidden="true"></i>',
                        '/' . $liquidationDocument->file_path,
                        ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Abrir']
                    ) ?>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="es-icon es-icon-neutral">
                    <i class="bi bi-file-earmark-x" aria-hidden="true"></i>
                </div>
                <div class="es-title">Sin documento de liquidación</div>
            </div>
            <?php endif; ?>
        </div>

        <?php /* ── Soportes ──────────────────────────────────── */ ?>
        <?php
        $docGroups = [];
        $multipleDocStatuses = count($documentsByStatus) > 1;
        foreach ($documentsByStatus as $status => $docs) {
            $rows = [];
            foreach ($docs as $docFile) {
                $rows[] = [
                    'doc'          => $docFile,
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

        <!-- Historial de Cambios del Grupo -->
        <?= $this->element('change_history', [
            'histories'       => $groupHistories,
            'fieldLabels'     => $fieldLabels,
            'title'           => 'Historial de Cambios del Grupo',
            'showNoveltyLink' => true,
        ]) ?>

    </main>
</div><!-- /spi-invoice-view-grid -->
<?= $this->element('observations/drawer', [
    'observations'    => $doc->novelty_observations ?? [],
    'count'           => count($doc->novelty_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $doc->id],
    'currentUserName' => $currentUser->full_name ?? ($currentUser->username ?? 'Usuario'),
]) ?>

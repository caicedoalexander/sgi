<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\NoveltyLiquidationDocEditViewModel $viewModel
 */
use App\Constants\NoveltyConstants;

$this->assign('title', $viewModel->pageTitle);

$doc                 = $viewModel->doc;
$roleName            = $viewModel->roleName;
$groupErrors         = $viewModel->groupErrors;
$effectiveStatuses   = $viewModel->effectiveStatuses;
$documentsByStatus   = $viewModel->documentsByStatus;
$liquidationDocument = $viewModel->liquidationDocument;
$currentUser         = $viewModel->currentUser;
$skipsGdp            = $viewModel->skipsGdp;
$bankingEntities     = $viewModel->bankingEntities;
$canRegisterPayment  = $viewModel->canRegisterPayment;
$canAuthorizePayment = $viewModel->canAuthorizePayment;
$canConfirmPayment   = $viewModel->canConfirmPayment;

$statusLabels      = $viewModel->statusLabels;
$periodLabels      = $viewModel->periodLabels;
$signerLabels      = $viewModel->signerLabels;
$paymentLabels     = $viewModel->paymentLabels;
$statusBadgeMap    = $viewModel->statusBadgeMap;
$badgeColors       = $viewModel->badgeColors;
$isRejected        = $viewModel->isRejected;
$isPaid            = $viewModel->isPaid;
$isFinal           = $viewModel->isFinal;
$currentStatus     = $viewModel->currentStatus;
$ps                = $viewModel->currentStatusBadge;
$showUploadSection = $viewModel->showUploadSection;
$totalDocs         = $viewModel->totalDocs;
$noveltyCount      = $viewModel->noveltyCount;
?>
<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Liquidaciones', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <?= $this->Html->link(h($doc->liquidation_number), ['action' => 'view', $doc->id]) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Editar</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title">Editar Liquidación</span>
            <span class="sgi-edit-id-chip"><?= h($doc->liquidation_number) ?></span>
            <?php if ($isRejected): ?>
                <span class="pill pill-danger-soft">Rechazada</span>
            <?php else: ?>
                <span class="pill <?= $ps[1] ?>"><?= $ps[0] ?></span>
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
            ['action' => 'view', $doc->id],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Advance warning -->
<?php if (!$isFinal && !empty($groupErrors)): ?>
<div class="alert alert-warning mb-4">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>
            <strong>Para avanzar al siguiente estado complete:</strong>
            <ul class="mb-0 mt-1 ps-3">
                <?php foreach ($groupErrors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="sgi-invoice-view-grid view-anim">

    <!-- ═════════════════════ SIDEBAR ═════════════════════ -->
    <aside class="sgi-invoice-view-left">
        <?php
        $registryLines = [
            ['icon' => 'bi-person', 'html' => 'Rol: <strong style="color:var(--text-default);">' . h($roleName) . '</strong>'],
        ];
        if ($doc->performed_by_user) {
            $registryLines[] = ['icon' => 'bi-person-badge', 'html' => 'Elaborado por ' . h($doc->performed_by_user->full_name)];
        }
        if ($doc->document_date) {
            $registryLines[] = ['icon' => 'bi-calendar3', 'html' => 'Documento · <span class="mono">' . $doc->document_date->format('d/m/Y') . '</span>'];
        }
        if ($doc->payment_date) {
            $registryLines[] = ['icon' => 'bi-cash-coin', 'html' => 'Pagado · <span class="mono">' . $doc->payment_date->format('d/m/Y') . '</span>'];
        }

        // Línea extra debajo del título: passes_for_payment + estado de pago
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
            'statusPill'     => $ps[1],
            'statusLabel'    => $ps[0],
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

        <!-- Novedades Asociadas -->
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-people" aria-hidden="true"></i>
                    Novedades Asociadas
                    <span class="sgi-folder-count"><?= $noveltyCount ?></span>
                </span>
            </div>
            <?php if (!empty($doc->employee_novelties)): ?>
            <div style="max-height:280px;overflow-y:auto;">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>Tipo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($doc->employee_novelties as $novelty): ?>
                        <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id]) ?>">
                            <td><?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?></td>
                            <td style="font-size:var(--fs-body-lg);"><?= h($novelty->novelty_type->name ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center sgi-fg-faint py-3" style="font-size:var(--fs-body);">
                <i class="bi bi-inbox me-1" aria-hidden="true"></i>No hay novedades asociadas.
            </div>
            <?php endif; ?>
        </div>

        <!-- Firmas -->
        <?php
        $signaturesVisibleStatuses = [
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_PAGADA,
        ];
        ?>
        <?php if (in_array($currentStatus, $signaturesVisibleStatuses) || ($isRejected && !empty($doc->novelty_liquidation_signatures))): ?>
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
                            <span class="pill pill-primary-soft"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Firmado</span>
                            <div class="mt-2" style="font-size:var(--fs-body-sm);color:var(--text-muted);line-height:1.4;">
                                <?= h($sig->signed_by_user->full_name ?? '') ?>
                                <?php if ($sig->approved_at): ?>
                                <div class="mono" style="font-size:var(--fs-meta);"><?= $sig->approved_at->format('d/m/Y H:i') ?></div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="pill pill-warning-soft">Pendiente</span>
                        <?php endif; ?>

                        <?php
                        $canToggle = ($sig->signer_type === NoveltyConstants::SIGNER_TRABAJADOR)
                            ? ($currentStatus === NoveltyConstants::STATUS_GDP)
                            : ($currentStatus === NoveltyConstants::STATUS_REVISION_FIRMAS);
                        ?>
                        <?php if ($canToggle): ?>
                        <div class="mt-3">
                            <?= $this->Form->create(null, ['url' => ['action' => 'addSignature', $doc->id], 'class' => 'd-inline']) ?>
                            <input type="hidden" name="signer_type" value="<?= h($sig->signer_type) ?>">
                            <?php if ($sig->signature_path): ?>
                            <input type="hidden" name="signature_status" value="pending">
                            <button type="submit" class="btn btn-sm btn-ghost-card">
                                <i class="bi bi-x-circle" aria-hidden="true"></i>Marcar Pendiente
                            </button>
                            <?php else: ?>
                            <input type="hidden" name="signature_status" value="signed">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Marcar Firmado
                            </button>
                            <?php endif; ?>
                            <?= $this->Form->end() ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stage-specific action forms -->
        <?php if (!$isFinal): ?>
        <?php
        $stageFormHtml = '';
        ob_start();

        if ($currentStatus === NoveltyConstants::STATUS_GDP):
            echo $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]); ?>
            <div class="d-flex flex-wrap gap-3 align-items-end">
                <div style="min-width:200px;">
                    <label class="form-label">Pasa para Pago</label>
                    <select name="passes_for_payment" class="form-select" required>
                        <option value="">-- Seleccione --</option>
                        <option value="1" <?= $doc->passes_for_payment === true ? 'selected' : '' ?>>Sí</option>
                        <option value="0" <?= $doc->passes_for_payment === false ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary flex-shrink-0">
                    <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Guardar y Avanzar
                </button>
            </div>
            <?= $this->Form->end();

        elseif ($currentStatus === NoveltyConstants::STATUS_CONTABILIDAD):
            echo $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]); ?>
            <div class="d-flex flex-wrap gap-3 align-items-end">
                <div style="min-width:180px;">
                    <label class="form-label" style="font-size:.8rem;">Fecha Documento</label>
                    <input type="text" name="document_date" class="form-control form-control-sm flatpickr-date"
                           value="<?= $doc->document_date?->format('Y-m-d') ?>">
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Guardar y Avanzar a <?= h($statusLabels[NoveltyConstants::STATUS_REVISION_FIRMAS] ?? '') ?>
                </button>
            </div>
            <?= $this->Form->end();

        elseif ($currentStatus === NoveltyConstants::STATUS_REVISION_FIRMAS):
            echo $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]); ?>
            <div class="d-flex flex-wrap gap-3 align-items-end">
                <?php if ($skipsGdp): ?>
                <div style="min-width:200px;">
                    <label class="form-label">Pasa para Pago</label>
                    <select name="passes_for_payment" class="form-select" required>
                        <option value="">-- Seleccione --</option>
                        <option value="1" <?= $doc->passes_for_payment === true ? 'selected' : '' ?>>Sí</option>
                        <option value="0" <?= $doc->passes_for_payment === false ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary flex-shrink-0">
                    <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Guardar y Avanzar
                </button>
            </div>
            <?= $this->Form->end();

        elseif ($currentStatus === NoveltyConstants::STATUS_RRHH):
            echo $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id], 'class' => 'd-inline']);
            ?>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Avanzar a <?= h($statusLabels[NoveltyConstants::STATUS_CONTABILIDAD] ?? 'Contabilidad') ?>
            </button>
            <?= $this->Form->end();
        endif;

        $stageFormHtml = trim(ob_get_clean());
        ?>

        <?php if ($stageFormHtml !== ''): ?>
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>Acción del paso actual
                </span>
            </div>
            <?= $stageFormHtml ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Payments section -->
        <?php
        $showPayments = in_array($currentStatus, [
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_AUTORIZACION_PAGO,
            NoveltyConstants::STATUS_VERIFICACION_PAGO,
            NoveltyConstants::STATUS_PAGADA,
        ]);
        ?>
        <?php if ($showPayments): ?>
        <?= $this->element('payment_section', [
            'payments'           => $doc->liquidation_doc_payments ?? [],
            'bankingEntities'    => $bankingEntities,
            'addPaymentUrl'      => ['controller' => 'LiquidationDocPayments', 'action' => 'addPayment', $doc->id],
            'authorizeUrlFn'     => fn($pId) => ['controller' => 'LiquidationDocPayments', 'action' => 'authorizePayment', $doc->id, $pId],
            'rejectUrlFn'        => fn($pId) => ['controller' => 'LiquidationDocPayments', 'action' => 'rejectPayment', $doc->id, $pId],
            'canRegisterPayment' => ($canRegisterPayment ?? false) && $currentStatus === NoveltyConstants::STATUS_TESORERIA,
            'canAuthorize'       => $canAuthorizePayment ?? false,
            'canDelete'          => false,
            'rejectMessage'      => '¿Rechazar este pago? El documento volverá a Tesorería.',
        ]) ?>
        <?= $this->element('confirm_payment_card', [
            'isVerificacionPago' => $currentStatus === NoveltyConstants::STATUS_VERIFICACION_PAGO,
            'canConfirm' => $canConfirmPayment ?? false,
            'confirmUrl' => ['controller' => 'LiquidationDocPayments', 'action' => 'confirmPayment', $doc->id],
        ]) ?>
        <?php endif; ?>

        <!-- Soportes + Observaciones (grid lateral) -->
        <?php
        $canUploadLiqDoc = $currentStatus === NoveltyConstants::STATUS_CONTABILIDAD && !$liquidationDocument;
        $canUpdateLiqDoc = $liquidationDocument && in_array($currentStatus, [
            NoveltyConstants::STATUS_CONTABILIDAD,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
        ]);
        ?>
        <div class="sgi-edit-side-grid">

            <!-- Soportes -->
            <div class="card" style="padding:18px 20px;display:flex;flex-direction:column;">
                <div class="sgi-section-head" style="margin-bottom:12px;">
                    <span class="sgi-label d-inline-flex align-items-center gap-2">
                        <i class="bi bi-paperclip" aria-hidden="true"></i>
                        Soportes
                        <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
                    </span>
                    <?php if ($showUploadSection): ?>
                    <button type="button" class="btn btn-ghost-card btn-sm" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                        <i class="bi bi-upload" aria-hidden="true"></i>Subir
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Documento de Liquidación (fila destacada) -->
                <div style="padding:.3rem .6rem;background:var(--primary-soft);display:flex;align-items:center;gap:.4rem;margin-bottom:8px;">
                    <span class="pill pill-primary">D. Liquidación</span>
                </div>
                <?php if ($liquidationDocument): ?>
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
                    <div style="display:flex;gap:.25rem;flex-shrink:0;">
                        <?php if ($canUpdateLiqDoc): ?>
                        <?= $this->Form->create(null, [
                            'url' => ['action' => 'updateLiquidationDocument', $doc->id],
                            'type' => 'file',
                            'id' => 'liq-doc-update-form',
                            'class' => 'd-inline',
                        ]) ?>
                        <input type="file" name="liquidation_file" id="liq-doc-file" required
                               accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx"
                               style="display:none;"
                               data-liq-trigger="liq-doc-update-form">
                        <label for="liq-doc-file" class="btn btn-sm btn-ghost-card" style="width:28px;height:28px;padding:0;line-height:28px;text-align:center;cursor:pointer;" title="Reemplazar">
                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                        </label>
                        <?= $this->Form->end() ?>
                        <?php endif; ?>
                        <?= $this->Html->link(
                            '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>',
                            '/' . $liquidationDocument->file_path,
                            ['class' => 'btn btn-sm btn-ghost-card', 'style' => 'width:28px;height:28px;padding:0;line-height:28px;text-align:center;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
                        ) ?>
                    </div>
                </div>
                <?php elseif ($canUploadLiqDoc): ?>
                <div style="display:flex;align-items:center;gap:.75rem;padding:.8rem .75rem;background:var(--primary-soft);margin-bottom:10px;">
                    <div style="width:34px;height:34px;flex-shrink:0;background:#fff;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-file-earmark-x" style="color:var(--text-disabled);font-size:1rem;" aria-hidden="true"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <span style="font-size:var(--fs-body-sm);color:var(--text-faint);">Sin documento</span>
                    </div>
                    <?= $this->Form->create(null, [
                        'url' => ['action' => 'uploadLiquidationDocument', $doc->id],
                        'type' => 'file',
                        'id' => 'liq-doc-upload-form',
                        'class' => 'd-inline flex-shrink-0',
                    ]) ?>
                    <input type="file" name="liquidation_file" id="liq-doc-file-new" required
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx"
                           style="display:none;"
                           data-liq-trigger="liq-doc-upload-form">
                    <label for="liq-doc-file-new" class="btn btn-sm btn-primary" style="padding:.25rem .5rem;font-size:.72rem;line-height:1;cursor:pointer;" title="Subir documento">
                        <i class="bi bi-upload me-1" aria-hidden="true"></i>Subir
                    </label>
                    <?= $this->Form->end() ?>
                </div>
                <?php else: ?>
                <div style="display:flex;align-items:center;gap:.75rem;padding:.8rem .75rem;background:var(--bg-muted);margin-bottom:10px;">
                    <i class="bi bi-file-earmark-x" style="color:var(--text-disabled);" aria-hidden="true"></i>
                    <span style="font-size:var(--fs-body-sm);color:var(--text-disabled);">Sin documento</span>
                </div>
                <?php endif; ?>

                <!-- Lista de soportes -->
                <div id="docs-empty-state" class="sgi-dropzone-empty" <?= !empty($documentsByStatus) ? 'style="display:none;"' : '' ?>>
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                    <div>Sin soportes adjuntos</div>
                </div>
                <div id="docs-list" style="max-height:420px;overflow-y:auto;">
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
                            'canDelete'    => $showUploadSection && $docFile->pipeline_status === $currentStatus,
                            'deleteUrl'    => $this->Url->build(['action' => 'deleteDocument', $doc->id, $docFile->id]),
                            'showBadge'    => !$multipleStatuses,
                            'badgeColors'  => $badgeColors,
                            'statusLabels' => $statusLabels,
                        ]) ?>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Observaciones -->
            <?php $obsCount = count($doc->novelty_observations ?? []); ?>
            <div class="card sgi-obs-card" style="padding:18px 20px;display:flex;flex-direction:column;">
                <div class="sgi-section-head" style="margin-bottom:12px;">
                    <span class="sgi-label d-inline-flex align-items-center gap-2">
                        <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                        Observaciones
                        <span id="obs-count" class="sgi-folder-count" <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
                    </span>
                </div>

                <div id="obs-chat-scroll" class="sgi-obs-list">
                    <?php foreach ($doc->novelty_observations ?? [] as $obs): ?>
                        <?= $this->element('observation_bubble', [
                            'observation' => $obs,
                            'isMine' => $currentUser && $obs->user_id === $currentUser->id,
                        ]) ?>
                    <?php endforeach; ?>
                </div>

                <div id="obs-empty-state" class="sgi-obs-empty" <?= $obsCount > 0 ? 'hidden' : '' ?>>
                    <i class="bi bi-chat-square-dots" aria-hidden="true" style="font-size:1.5rem;"></i>
                    <span style="font-size:var(--fs-body-sm);">Sin observaciones aún</span>
                </div>

                <?php if (!$isFinal): ?>
                <div class="sgi-obs-input-bar">
                    <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $doc->id], 'id' => 'obs-form']) ?>
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

        </div><!-- /sgi-edit-side-grid -->

    </main>
</div><!-- /sgi-invoice-view-grid -->

<!-- Upload Document Modal -->
<?php if ($showUploadSection): ?>
<div class="modal fade" id="uploadDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="upload-doc-form"
                  data-url="<?= $this->Url->build(['action' => 'uploadDocument', $doc->id]) ?>"
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
<?= $this->element('observation_chat_init') ?>

<?= $this->Html->script('sgi-signature') ?>
<?= $this->Html->script('sgi-epadlink') ?>

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

    var csrfToken = <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>;
    document.querySelectorAll('input[data-liq-trigger]').forEach(function (input) {
        input.addEventListener('change', function () {
            if (!input.files || !input.files.length) return;
            var form = document.getElementById(input.dataset.liqTrigger);
            if (!form) return;
            var data = new FormData(form);
            var label = form.querySelector('label[for="' + input.id + '"]');
            if (label) label.style.pointerEvents = 'none';
            fetch(form.action, {
                method: 'POST',
                body: data,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                },
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Respuesta inválida del servidor.' }; }); })
            .then(function (json) {
                if (json && json.success) {
                    window.location.reload();
                    return;
                }
                window.alert((json && json.error) || 'Error al subir el documento.');
                if (label) label.style.pointerEvents = '';
                input.value = '';
            })
            .catch(function () {
                window.alert('Error de conexión. Intente nuevamente.');
                if (label) label.style.pointerEvents = '';
                input.value = '';
            });
        });
    });
})();
</script>
<?php $this->end() ?>

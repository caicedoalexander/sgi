<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\NoveltyLiquidationDocEditViewModel $viewModel
 */

$this->assign('title', $viewModel->pageTitle);

// Alias locales para mantener el markup corto.
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
$statusIcons       = $viewModel->statusIcons;
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
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Liquidación</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1" aria-hidden="true"></i>Ver',
            ['action' => 'view', $doc->id],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
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

<!-- Two-column responsive layout -->
<div class="sgi-invoice-layout">

<!-- Left column: main content -->
<div class="sgi-invoice-form">
<div class="card card-primary mb-4">

    <!-- Card header -->
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="sgi-icon-chip">
                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
            </div>
            <div>
                <div class="sgi-card-title mono">
                    <?= h($doc->liquidation_number) ?>
                </div>
                <div class="sgi-card-subtitle mt-1">
                    <?= $periodLabels[$doc->period] ?? h($doc->period) ?>
                </div>
            </div>
        </div>
        <span class="pill <?= $ps[1] ?>"><?= $ps[0] ?></span>
    </div>

    <!-- Pipeline progress -->
    <div class="sgi-pipeline-wrapper">
        <?= $this->element('pipeline_progress', [
            'pipelineStatuses' => $effectiveStatuses,
            'pipelineLabels'   => $statusLabels,
            'currentStatus'    => $currentStatus,
            'isRejected'       => $isRejected,
            'statusIcons'      => $statusIcons,
        ]) ?>
    </div>

    <!-- ── Ficha resumen (ledger) ── -->
    <div class="sgi-ledger-wrapper">
        <div class="sgi-ledger">
            <!-- Fila 1: No. Liquidación + Período + Novedades -->
            <div class="sgi-ledger-item" style="grid-column:span 2;">
                <div class="sgi-ledger-label">No. Liquidación</div>
                <div class="sgi-ledger-value mono"><?= h($doc->liquidation_number) ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Período</div>
                <div class="sgi-ledger-value"><?= h($periodLabels[$doc->period] ?? $doc->period) ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Novedades</div>
                <div class="sgi-ledger-value --amount"><?= $noveltyCount ?></div>
            </div>
            <!-- Fila 2: Elaborado por + Fecha Documento + Pasa para Pago + Estado Pago -->
            <div class="sgi-ledger-item" style="grid-column:span 2;">
                <div class="sgi-ledger-label">Elaborado por</div>
                <div class="sgi-ledger-value"><?= h($doc->performed_by_user->full_name ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Fecha Documento</div>
                <div class="sgi-ledger-value"><?= $doc->document_date?->format('d/m/Y') ?: '—' ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Pasa para Pago</div>
                <div class="sgi-ledger-value">
                    <?php if ($doc->passes_for_payment === true): ?>
                        <span style="color:var(--primary-color);font-weight:600;">Sí</span>
                    <?php elseif ($doc->passes_for_payment === false): ?>
                        <span style="color:var(--text-disabled);">No</span>
                    <?php else: ?>
                        <span class="--muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($doc->payment_status): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Estado de Pago</div>
                <div class="sgi-ledger-value"><?= h($paymentLabels[$doc->payment_status] ?? $doc->payment_status) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($doc->payment_date): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Fecha de Pago</div>
                <div class="sgi-ledger-value"><?= $doc->payment_date->format('d/m/Y') ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body p-4" style="padding-top:0 !important;">

        <!-- Section: Novedades Asociadas -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">
                    <i class="bi bi-people me-1" aria-hidden="true"></i>Novedades Asociadas (<?= $noveltyCount ?>)
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <?php if (!empty($doc->employee_novelties)): ?>
            <div style="max-height:250px;overflow-y:auto;">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Empleado</th><th>Tipo</th></tr></thead>
                    <tbody>
                        <?php foreach ($doc->employee_novelties as $novelty): ?>
                        <tr>
                            <td>
                                <?= $this->Html->link(
                                    h($novelty->custom_name ?: $novelty->employee->full_name ?? '—'),
                                    ['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id],
                                    ['class' => 'text-decoration-none']
                                ) ?>
                            </td>
                            <td style="font-size:var(--fs-body-lg);"><?= h($novelty->novelty_type->name ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="text-muted small mb-0">No hay novedades asociadas.</p>
            <?php endif; ?>
        </div>

        <!-- Signatures Section (in revision_firmas stage or if signatures exist) -->
        <?php
        $signaturesVisibleStatuses = [
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_PAGADA,
        ];
        ?>
        <?php if (in_array($doc->pipeline_status, $signaturesVisibleStatuses) || ($doc->pipeline_status === NoveltyConstants::STATUS_RECHAZADA && !empty($doc->novelty_liquidation_signatures))): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">
                    <i class="bi bi-pen me-1" aria-hidden="true"></i>Firmas
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <?php foreach ($doc->novelty_liquidation_signatures as $sig): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="border p-3 text-center h-100" style="border-radius:2px;">
                        <div class="fw-bold small mb-2"><?= $signerLabels[$sig->signer_type] ?? h($sig->signer_type) ?></div>
                        <?php if ($sig->signature_path): ?>
                            <span class="pill pill-primary-soft"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Firmado</span>
                            <div class="mt-1 small text-muted">
                                <?= h($sig->signed_by_user->full_name ?? '') ?>
                                <?php if ($sig->approved_at): ?>
                                <br><?= $sig->approved_at->format('d/m/Y H:i') ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="pill pill-secondary-soft">Pendiente</span>
                        <?php endif; ?>

                        <?php
                        $canToggle = ($sig->signer_type === NoveltyConstants::SIGNER_TRABAJADOR)
                            ? ($doc->pipeline_status === NoveltyConstants::STATUS_GDP)
                            : ($doc->pipeline_status === NoveltyConstants::STATUS_REVISION_FIRMAS);
                        ?>
                        <?php if ($canToggle): ?>
                        <div class="mt-2">
                            <?= $this->Form->create(null, ['url' => ['action' => 'addSignature', $doc->id], 'class' => 'd-inline']) ?>
                            <input type="hidden" name="signer_type" value="<?= h($sig->signer_type) ?>">
                            <?php if ($sig->signature_path): ?>
                            <input type="hidden" name="signature_status" value="pending">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Marcar Pendiente
                            </button>
                            <?php else: ?>
                            <input type="hidden" name="signature_status" value="signed">
                            <button type="submit" class="btn btn-sm btn-outline-success">
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

        <!-- Stage-specific action forms (sticky) -->
        <?php if (!$isFinal): ?>
        <div class="sgi-sticky-actions">

            <?php if ($currentStatus === NoveltyConstants::STATUS_GDP): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]) ?>
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
            <?= $this->Form->end() ?>

            <?php elseif ($currentStatus === NoveltyConstants::STATUS_CONTABILIDAD): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]) ?>
            <div class="d-flex flex-wrap gap-3 align-items-end">
                <div style="min-width:180px;">
                    <label class="form-label" style="font-size:.8rem;">Fecha Documento</label>
                    <input type="text" name="document_date" class="form-control form-control-sm flatpickr-date"
                           value="<?= $doc->document_date?->format('Y-m-d') ?>">
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Guardar y Avanzar a <?= $statusLabels[NoveltyConstants::STATUS_REVISION_FIRMAS] ?? '' ?>
                </button>
            </div>
            <?= $this->Form->end() ?>

            <?php elseif ($currentStatus === NoveltyConstants::STATUS_REVISION_FIRMAS): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]) ?>
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
            <?= $this->Form->end() ?>

            <?php elseif ($currentStatus === NoveltyConstants::STATUS_RRHH): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id], 'class' => 'd-inline']) ?>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Avanzar a <?= $statusLabels[NoveltyConstants::STATUS_CONTABILIDAD] ?? 'Contabilidad' ?>
            </button>
            <?= $this->Form->end() ?>

            <?php endif; ?>
        </div>
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
    <div class="mt-4">
        <?= $this->element('payment_section', [
            'payments'           => $doc->liquidation_doc_payments ?? [],
            'bankingEntities'    => $bankingEntities,
            'addPaymentUrl'      => ['controller' => 'LiquidationDocPayments', 'action' => 'addPayment', $doc->id],
            'authorizeUrlFn'     => fn($pId) => ['controller' => 'LiquidationDocPayments', 'action' => 'authorizePayment', $doc->id, $pId],
            'rejectUrlFn'        => fn($pId) => ['controller' => 'LiquidationDocPayments', 'action' => 'rejectPayment', $doc->id, $pId],
            'canRegisterPayment' => ($canRegisterPayment ?? false) && $currentStatus === \App\Constants\NoveltyConstants::STATUS_TESORERIA,
            'canAuthorize'       => $canAuthorizePayment ?? false,
            'canDelete'          => false,
            'rejectMessage'      => '¿Rechazar este pago? El documento volverá a Tesorería.',
        ]) ?>
        <?= $this->element('confirm_payment_card', [
            'isVerificacionPago' => $currentStatus === \App\Constants\NoveltyConstants::STATUS_VERIFICACION_PAGO,
            'canConfirm' => $canConfirmPayment ?? false,
            'confirmUrl' => ['controller' => 'LiquidationDocPayments', 'action' => 'confirmPayment', $doc->id],
        ]) ?>
    </div>
    <?php endif; ?>

</div><!-- /card-body -->
</div><!-- /card -->
</div><!-- /left column -->

<!-- Right column: documents + observations -->
<div class="sgi-invoice-sidebar">

<!-- Documents panel -->
<?php
$canUploadLiqDoc = $currentStatus === NoveltyConstants::STATUS_CONTABILIDAD && !$liquidationDocument;
$canUpdateLiqDoc = $liquidationDocument && in_array($currentStatus, [
    NoveltyConstants::STATUS_CONTABILIDAD,
    NoveltyConstants::STATUS_REVISION_FIRMAS,
    NoveltyConstants::STATUS_GDP,
]);
?>
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

    <!-- Liquidation document row (inline, compact) -->
    <div style="padding:.3rem .875rem;background:rgba(70,157,97,.06);border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.4rem;">
        <span class="pill" style="font-size:var(--fs-micro);background:var(--primary-color);color:#fff;">D. Liquidación</span>
    </div>
    <?php if ($liquidationDocument): ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);background:rgba(70,157,97,.03);">
        <div style="width:34px;height:34px;flex-shrink:0;background:var(--background-color);border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
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
            <label for="liq-doc-file" class="btn btn-sm btn-outline-primary" style="width:28px;height:28px;padding:0;font-size:var(--fs-body-sm);line-height:28px;text-align:center;cursor:pointer;" title="Reemplazar">
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            </label>
            <?= $this->Form->end() ?>
            <?php endif; ?>
            <?= $this->Html->link(
                '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>',
                '/' . $liquidationDocument->file_path,
                ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'width:28px;height:28px;padding:0;font-size:var(--fs-body-sm);line-height:28px;text-align:center;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
            ) ?>
        </div>
    </div>
    <div style="height:2px;background:var(--primary-color);opacity:.35;"></div>
    <?php elseif ($canUploadLiqDoc): ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);background:rgba(70,157,97,.03);">
        <div style="width:34px;height:34px;flex-shrink:0;background:var(--background-color);border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
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
        <label for="liq-doc-file-new" class="btn btn-sm btn-outline-primary" style="padding:.25rem .5rem;font-size:.72rem;line-height:1;cursor:pointer;" title="Subir documento">
            <i class="bi bi-upload me-1" aria-hidden="true"></i>Subir
        </label>
        <?= $this->Form->end() ?>
    </div>
    <div style="height:2px;background:var(--primary-color);opacity:.35;"></div>
    <?php else: ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);background:rgba(70,157,97,.03);">
        <div style="width:34px;height:34px;flex-shrink:0;background:var(--background-color);border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-file-earmark-x" style="color:var(--text-disabled);font-size:1rem;" aria-hidden="true"></i>
        </div>
        <span style="font-size:var(--fs-body-sm);color:var(--text-disabled);">Sin documento</span>
    </div>
    <div style="height:2px;background:var(--primary-color);opacity:.35;"></div>
    <?php endif; ?>

    <div id="docs-empty-state" style="padding:1.5rem 1rem;text-align:center;color:var(--text-disabled);<?= !empty($documentsByStatus) ? 'display:none;' : '' ?>">
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
            <span class="pill <?= $badgeColors[$status] ?? 'pill-muted' ?>" style="font-size:var(--fs-micro);"><?= $statusLabels[$status] ?? $status ?></span>
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

<!-- Observations chat -->
<?php $obsCount = count($doc->novelty_observations ?? []); ?>
<div class="card card-primary sgi-obs-card" style="display:flex;flex-direction:column;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);" aria-hidden="true"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <span id="obs-count" class="sgi-folder-count ms-auto" <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
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
        <i class="bi bi-chat-square-dots" style="font-size:1.75rem;" aria-hidden="true"></i>
        <span style="font-size:var(--fs-body);">Sin observaciones aún</span>
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

</div><!-- /right column -->
</div><!-- /two-column layout -->

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

<?= $this->Html->script('sgi-signature') ?>
<?= $this->Html->script('sgi-epadlink') ?>

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

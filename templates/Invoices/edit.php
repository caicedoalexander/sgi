<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var \App\Model\Entity\User|null $currentUser
 */

use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

$this->assign('title', $viewModel->pageTitle);

// Alias locales: convenientes para mantener el markup corto.
$isAdvance              = $viewModel->isAdvance;
$documentTypes          = $viewModel->documentTypes;
$approvalOptions        = $viewModel->approvalOptions;
$dianOptions            = $viewModel->dianOptions;
$readyForPaymentOptions = $viewModel->readyForPaymentOptions;
$paymentStatusOptions   = $viewModel->paymentStatusOptions;
$renderOrder            = $viewModel->renderOrder;
$readOnlySectionKeys    = $viewModel->readOnlySectionKeys;
$canEdit                = fn(string $field): bool => $viewModel->canEditField($field);
$isReadOnlySection      = fn(string $s): bool      => $viewModel->isReadOnlySection($s);

$btnLabel = $viewModel->submitButtonHtml;
$btnClass = $viewModel->submitButtonClass;

$currentStatus = $viewModel->currentStatus;
$currentLabel  = $viewModel->pipelineLabels[$currentStatus] ?? $currentStatus;
$nextLabel     = $viewModel->nextStatus !== null
    ? ($viewModel->pipelineLabels[$viewModel->nextStatus] ?? $viewModel->nextStatus)
    : null;

// Mapeo etapa → acento (encabezado de la card de etapa actual).
$stageAccentMap = [
    InvoiceConstants::STATUS_APROBACION        => 'is-warning',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'is-secondary',
    InvoiceConstants::STATUS_TESORERIA         => 'is-accent',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'is-warning',
    InvoiceConstants::STATUS_VERIFICACION_PAGO => 'is-info',
    InvoiceConstants::STATUS_PAGADA            => '',
    InvoiceConstants::STATUS_LEGALIZADA        => '',
];
$stageAccent = $viewModel->isRejected ? 'is-danger' : ($stageAccentMap[$currentStatus] ?? '');

// Pill kind del estado para el resumen / sticky footer.
$statusPills = [
    InvoiceConstants::STATUS_APROBACION        => 'pill-warning-soft',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'pill-secondary-soft',
    InvoiceConstants::STATUS_TESORERIA         => 'pill-info-soft',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
    InvoiceConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
    InvoiceConstants::STATUS_PAGADA            => 'pill-primary-soft',
    InvoiceConstants::STATUS_LEGALIZADA        => 'pill-primary-soft',
];
$statusPill = $statusPills[$currentStatus] ?? 'pill-muted';

// ── Ledger lookup arrays (ResultSet → array para acceso por índice) ──
$expenseTypesArr = is_array($viewModel->expenseTypes)
    ? $viewModel->expenseTypes
    : (method_exists($viewModel->expenseTypes, 'toArray') ? $viewModel->expenseTypes->toArray() : []);
$costCentersArr = is_array($viewModel->costCenters)
    ? $viewModel->costCenters
    : (method_exists($viewModel->costCenters, 'toArray') ? $viewModel->costCenters->toArray() : []);

// ── Soportes ─────────────────────────────────────────────────────
$showUploadSection = !$viewModel->invoice->isInFinalState();
$documentsByStatus = [];
if (!empty($viewModel->invoice->invoice_documents)) {
    foreach ($viewModel->invoice->invoice_documents as $doc) {
        $documentsByStatus[$doc->pipeline_status][] = $doc;
    }
}
$statusLabels = InvoiceConstants::STATUS_LABELS;
$badgeColors = InvoicePresentation::STATUS_BADGES;
$totalDocs = array_sum(array_map('count', $documentsByStatus));

// ── Resumen monetario ────────────────────────────────────────────
$invoiceAmount = (float)($viewModel->invoice->amount ?? 0);
$totalPagado = (float)$viewModel->paymentsTotal;
$saldo = $invoiceAmount - $totalPagado;
$amountInt = number_format(floor($invoiceAmount), 0, ',', '.');
$amountDec = sprintf(',%02d', (int)round(($invoiceAmount - floor($invoiceAmount)) * 100));

// ── Pipeline vertical ────────────────────────────────────────────
$pipelineSteps = $viewModel->pipelineStatuses;
$currentIdx = array_search($currentStatus, $pipelineSteps, true);
if ($currentIdx === false) {
    $currentIdx = count($pipelineSteps);
}
$isTerminal = in_array($currentStatus, [InvoiceConstants::STATUS_PAGADA, InvoiceConstants::STATUS_LEGALIZADA], true);

// ── Beneficiario (Anticipo vs Factura) ───────────────────────────
$beneficiaryName = $viewModel->invoice->provider->name
    ?? ($viewModel->invoice->employee->full_name ?? '—');
?>

<?php if (($viewModel->invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_LEGALIZACION && !empty($viewModel->invoice->advance_id)): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>
            Esta factura es una <strong>Legalización</strong> vinculada al
            <?= $this->Html->link('Anticipo #' . h($viewModel->invoice->advance_id), ['controller' => 'Advances', 'action' => 'view', $viewModel->invoice->advance_id]) ?>.
        </div>
    </div>
<?php endif; ?>

<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<?= $this->element('invoice_edit/page_header', ['isAdvance' => $isAdvance]) ?>

<?= $this->element('invoice_edit/advance_alert') ?>

<?php if ($viewModel->canSendLinks): ?>
<?= $this->Form->create(null, [
    'url' => ['action' => 'sendApprovalLinks', $viewModel->invoice->id],
    'id' => 'sendApprovalLinksForm',
    'style' => 'display:none',
]) ?>
<?= $this->Form->end() ?>
<?php endif; ?>

<?= $this->Form->create($viewModel->invoice, ['id' => 'invoiceEditForm']) ?>
<?= $this->Form->hidden('expected_status', ['value' => $viewModel->invoice->pipeline_status]) ?>

<div class="sgi-invoice-view-grid view-anim">

    <!-- ═══════════════════════════ COLUMNA IZQUIERDA ═══════════════════════════ -->
    <aside class="sgi-invoice-view-left">

        <!-- Hero summary -->
        <div class="card" style="padding:20px;">
            <div class="d-flex align-items-start" style="gap:12px;margin-bottom:16px;">
                <div class="sgi-hero-icon">
                    <i class="bi <?= $isAdvance ? 'bi-cash-coin' : 'bi-file-earmark-text' ?>" aria-hidden="true"></i>
                </div>
                <div style="min-width:0;flex:1;">
                    <div class="sgi-hero-id"><?= h($viewModel->invoice->invoice_number ?? ('#' . $viewModel->invoice->id)) ?></div>
                    <div class="d-flex flex-wrap" style="gap:4px;margin-top:6px;">
                        <span class="pill pill-secondary"><?= h($viewModel->invoice->document_type) ?></span>
                        <?php if ($viewModel->isRejected): ?>
                            <span class="pill pill-danger-soft">Rechazada</span>
                        <?php elseif ($viewModel->isApproved): ?>
                            <span class="pill pill-primary-soft">
                                <i class="bi bi-check2" aria-hidden="true" style="font-size:9px;"></i>Aprobada
                            </span>
                        <?php else: ?>
                            <span class="pill <?= $statusPill ?>"><?= h($currentLabel) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="sgi-label"><?= $isAdvance ? 'Beneficiario' : 'Proveedor' ?></div>
            <div style="font-size:12.5px;font-weight:600;color:var(--text-default);margin-top:4px;line-height:1.3;">
                <?= h($beneficiaryName) ?>
            </div>
            <?php if ($viewModel->invoice->hasValue('operation_center')): ?>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;" class="d-flex align-items-center gap-1">
                <i class="bi bi-geo-alt" aria-hidden="true" style="font-size:11px;"></i>
                <span><?= h($viewModel->invoice->operation_center->name) ?></span>
            </div>
            <?php endif; ?>

            <div class="sgi-hero-divider">
                <div class="sgi-label"><?= $isAdvance ? 'Valor Anticipo' : 'Valor Factura' ?></div>
                <div class="d-flex align-items-baseline" style="gap:4px;margin-top:4px;">
                    <?php if ($invoiceAmount > 0): ?>
                        <span class="sgi-hero-display">$ <?= $amountInt ?></span>
                        <span class="sgi-hero-decimals"><?= $amountDec ?></span>
                    <?php else: ?>
                        <span class="sgi-hero-display" style="color:var(--text-disabled);">$ —</span>
                    <?php endif; ?>
                </div>

                <?php if ($totalPagado > 0 || $invoiceAmount > 0): ?>
                <div style="margin-top:14px;display:flex;gap:18px;">
                    <div>
                        <div class="sgi-label" style="font-size:9.5px;">Pagado</div>
                        <div style="font-size:13px;font-weight:700;color:var(--primary-color);font-family:var(--font-mono);margin-top:2px;">
                            $ <?= number_format($totalPagado, 0, ',', '.') ?>
                        </div>
                    </div>
                    <div>
                        <div class="sgi-label" style="font-size:9.5px;">Saldo</div>
                        <div style="font-size:13px;font-weight:700;color:<?= $saldo > 0 ? 'var(--secondary-color)' : 'var(--primary-color)' ?>;font-family:var(--font-mono);margin-top:2px;">
                            $ <?= number_format(max(0, $saldo), 0, ',', '.') ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pipeline vertical -->
        <div class="card" style="padding:20px;">
            <div class="sgi-section-head"><span class="sgi-label">Pipeline</span></div>
            <div class="sgi-pipeline-v">
                <?php foreach ($pipelineSteps as $idx => $stepKey):
                    $isDone = $idx < $currentIdx || ($isTerminal && $idx === $currentIdx);
                    $isCurrent = !$isTerminal && $idx === $currentIdx;
                    $stepLabel = $viewModel->pipelineLabels[$stepKey] ?? $stepKey;
                    $stepClasses = 'sgi-pipeline-v-step';
                    if ($isDone) { $stepClasses .= ' is-done'; }
                    if ($isCurrent) { $stepClasses .= ' is-current'; }
                    if ($isCurrent && $viewModel->isRejected) { $stepClasses .= ' is-rejected'; }

                    $stepMeta = null;
                    if ($isCurrent || ($isTerminal && $idx === $currentIdx)) {
                        $stepMeta = $viewModel->invoice->modified?->format('d/m H:i') ?? null;
                    } elseif (!$isDone) {
                        $stepMeta = 'Pendiente';
                    }
                ?>
                <div class="<?= $stepClasses ?>">
                    <div class="sgi-pipeline-v-marker">
                        <?php if ($isCurrent && $viewModel->isRejected): ?>
                            <i class="bi bi-x" aria-hidden="true"></i>
                        <?php elseif ($isDone): ?>
                            <i class="bi bi-check2" aria-hidden="true"></i>
                        <?php elseif ($isCurrent): ?>
                            <span class="dot"></span>
                        <?php endif; ?>
                    </div>
                    <div class="sgi-pipeline-v-content">
                        <div class="sgi-pipeline-v-label"><?= h($stepLabel) ?></div>
                        <?php if ($stepMeta): ?>
                            <div class="sgi-pipeline-v-meta"><?= h($stepMeta) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Acciones -->
        <?php $canRegress = !empty($viewModel->canRegress); ?>
        <?php if ($canRegress): ?>
        <?php
            $prevLabel = $viewModel->pipelineLabels[$viewModel->previousStatus] ?? $viewModel->previousStatus;
            $regressLocked = !empty($viewModel->regressLockMessage);
        ?>
        <div class="card" style="padding:16px 20px;">
            <div class="sgi-section-head" style="margin-bottom:10px;">
                <span class="sgi-label">Acciones</span>
            </div>
            <div class="sgi-edit-actions-list">
                <?php if ($regressLocked): ?>
                    <button type="button" class="btn" disabled title="<?= h($viewModel->regressLockMessage) ?>">
                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar al paso anterior
                    </button>
                <?php else: ?>
                    <button type="button" class="btn"
                            data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar a: <?= h($prevLabel) ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Auditoría / registro -->
        <div class="card" style="padding:16px 20px;">
            <div class="sgi-section-head" style="margin-bottom:10px;">
                <span class="sgi-label">Registro</span>
            </div>
            <div style="font-size:12px;color:var(--text-muted);" class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-person sgi-fg-faint" aria-hidden="true"></i>
                <span>Rol: <strong style="color:var(--text-default);"><?= h($viewModel->roleName) ?></strong></span>
            </div>
            <?php if ($viewModel->invoice->created): ?>
            <div style="font-size:11.5px;color:var(--text-muted);" class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-calendar3 sgi-fg-faint" aria-hidden="true"></i>
                <span>Creado · <span class="mono"><?= $viewModel->invoice->created->format('d/m/Y') ?></span></span>
            </div>
            <?php endif; ?>
            <?php if ($viewModel->invoice->modified): ?>
            <div style="font-size:11.5px;color:var(--text-muted);" class="d-flex align-items-center gap-2">
                <i class="bi bi-pencil-square sgi-fg-faint" aria-hidden="true"></i>
                <span>Modificado · <span class="mono"><?= $viewModel->invoice->modified->format('d/m/Y') ?></span></span>
            </div>
            <?php endif; ?>
        </div>

    </aside>

    <!-- ═══════════════════════════ COLUMNA DERECHA ═══════════════════════════ -->
    <main class="sgi-invoice-view-right">

        <?php
        // Las secciones general/dates/classification van a la card "Datos
        // Generales" SIEMPRE (los partials manejan el estado disabled cuando
        // el rol/estado no permite edicion). visibleSections del policy nunca
        // las incluye porque originalmente esos campos los pintaba el
        // sgi-ledger; ahora la card los renderiza siempre como inputs read-only
        // cuando aplica.
        $generalSectionKeys = ['general', 'dates', 'classification'];
        $stageSectionKeys = ['revision', 'accounting', 'treasury', 'payment_authorization'];
        $visibleGeneralSections = $generalSectionKeys;
        $visibleStageSections = array_values(array_intersect($stageSectionKeys, $viewModel->visibleSections));

        $sharedPaymentParams = [
            'payments'           => $viewModel->invoice->invoice_payments ?? [],
            'bankingEntities'    => $viewModel->bankingEntities,
            'addPaymentUrl'      => ['controller' => 'InvoicePayments', 'action' => 'addPayment', $viewModel->invoice->id],
            'authorizeUrlFn'     => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'authorizePayment', $viewModel->invoice->id, $pId],
            'rejectUrlFn'        => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'rejectPayment', $viewModel->invoice->id, $pId],
            'deleteUrlFn'        => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'deletePayment', $viewModel->invoice->id, $pId],
            'paymentStatus'      => $viewModel->invoice->payment_status ?? null,
            'totalAmount'        => $viewModel->invoice->amount ?? null,
            'rejectMessage'      => '¿Rechazar este pago? El registro volverá a Tesorería.',
            'with_idempotency_key' => true,
        ];

        $isPaymentStage = in_array($viewModel->currentStatus, [
            InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            InvoiceConstants::STATUS_VERIFICACION_PAGO,
            InvoiceConstants::STATUS_PAGADA,
        ], true);
        ?>

        <!-- Datos Generales: datos invariantes a la etapa -->
        <?php if (!empty($visibleGeneralSections)): ?>
        <div class="card sgi-edit-stage-card">
            <div class="sgi-edit-stage-head">
                <div>
                    <div class="sgi-edit-stage-eyebrow">Datos Generales</div>
                    <div class="sgi-edit-stage-title">Información del documento</div>
                </div>
                <div style="font-size:11px;color:var(--text-faint);font-style:italic;">
                    Estos campos no cambian con la etapa
                </div>
            </div>
            <div class="sgi-edit-stage-body">
                <?= $this->element('invoice_edit/sections/general_compact', compact('viewModel', 'canEdit', 'isAdvance', 'documentTypes')) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Etapa actual: campos editables específicos del estado -->
        <?php if (!empty($visibleStageSections)): ?>
        <div class="card sgi-edit-stage-card">
            <div class="sgi-edit-stage-head <?= $stageAccent ?>">
                <div>
                    <div class="sgi-edit-stage-eyebrow">Etapa actual · editable</div>
                    <div class="sgi-edit-stage-title">
                        <?= h($currentLabel) ?>
                        <?php if ($nextLabel && !$viewModel->isRejected): ?>
                            <span style="font-size:11px;color:var(--text-faint);font-weight:500;">→ próximo: <?= h($nextLabel) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="sgi-edit-stage-hint">
                    Campos con * son obligatorios para avanzar
                </div>
            </div>
            <div class="sgi-edit-stage-body">
                <div class="sgi-form-sections">
                    <?php foreach ($visibleStageSections as $sectionName): ?>

                    <?php if ($sectionName === 'revision'): ?>
                    <?= $this->element('invoice_edit/sections/revision', compact('viewModel', 'canEdit', 'approvalOptions', 'dianOptions')) ?>
                    <?php endif; ?>

                    <?php if ($sectionName === 'accounting'): ?>
                    <?= $this->element('invoice_edit/sections/accounting', compact('viewModel', 'canEdit', 'readyForPaymentOptions')) ?>
                    <?php endif; ?>

                    <?php if ($sectionName === 'treasury' && !$isPaymentStage): ?>
                    <?php
                        $canRegisterPayment = $viewModel->canRegisterPayment;
                        $paymentMode = $canRegisterPayment ? 'tesoreria_register' : 'view';
                    ?>
                    <?= $this->element('payment_section', $sharedPaymentParams + [
                        'canRegisterPayment' => $canRegisterPayment,
                        'canAuthorize'       => false,
                        'canDelete'          => $canRegisterPayment,
                        'mode'               => $paymentMode,
                    ]) ?>
                    <?php endif; ?>

                    <?php if ($sectionName === 'payment_authorization' && $isPaymentStage): ?>
                    <?php
                        $canAuthorizePayment = $viewModel->canAuthorizePayment;
                        $paymentMode = $canAuthorizePayment ? 'authorize' : 'view';
                    ?>
                    <?= $this->element('payment_section', $sharedPaymentParams + [
                        'canRegisterPayment' => false,
                        'canAuthorize'       => $canAuthorizePayment,
                        'canDelete'          => false,
                        'mode'               => $paymentMode,
                    ]) ?>
                    <?php endif; ?>

                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($visibleGeneralSections) && empty($visibleStageSections)): ?>
        <div class="sgi-edit-banner" style="background:var(--info-soft);border-left-color:var(--info-color);">
            <i class="bi bi-info-circle bi-banner" aria-hidden="true" style="color:var(--info-color);"></i>
            <div style="flex:1;min-width:0;">
                <div class="sgi-edit-banner-title">No tiene permisos de edición para esta factura en el estado actual.</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Confirmación de pago (Verificación) -->
        <?= $this->element('confirm_payment_card', [
            'isVerificacionPago' => $viewModel->currentStatus === InvoiceConstants::STATUS_VERIFICACION_PAGO,
            'canConfirm' => $viewModel->canConfirmPayment,
            'confirmUrl' => ['controller' => 'InvoicePayments', 'action' => 'confirmPayment', $viewModel->invoice->id],
        ]) ?>

        <!-- Soportes + Observaciones (grid 2 columnas) -->
        <div class="sgi-edit-side-grid">
            <?= $this->element('invoice_edit/sidebar', [
                'documentsByStatus' => $documentsByStatus,
                'totalDocs'         => $totalDocs,
                'showUploadSection' => $showUploadSection,
                'statusLabels'      => $statusLabels,
                'badgeColors'       => $badgeColors,
            ]) ?>
        </div>

        <!-- Email log (cuando aplica) -->
        <?= $this->element('email_log_panel', ['emailLogs' => $viewModel->emailLogs ?? []]) ?>

        <!-- Sticky footer: meta + acciones -->
        <?php if (!empty($viewModel->editableFields) || $canRegress): ?>
        <div class="sgi-edit-footer">
            <div class="sgi-edit-footer-meta">
                <span class="d-inline-flex align-items-center gap-1">
                    <i class="bi bi-person sgi-fg-faint" aria-hidden="true"></i>
                    Rol: <strong style="color:var(--text-default);"><?= h($viewModel->roleName) ?></strong>
                </span>
                <?php if ($viewModel->invoice->modified): ?>
                <span class="sep"></span>
                <span class="d-inline-flex align-items-center gap-1">
                    <i class="bi bi-clock sgi-fg-faint" aria-hidden="true"></i>
                    Última modificación: <span class="mono"><?= $viewModel->invoice->modified->format('d/m/Y H:i') ?></span>
                </span>
                <?php endif; ?>
                <span class="sep" data-dirty-indicator hidden></span>
                <span class="sgi-edit-dirty" data-dirty-indicator hidden>Hay cambios sin guardar</span>
            </div>
            <div class="sgi-edit-footer-actions">
                <?= $this->Html->link(
                    'Cancelar',
                    ['action' => 'view', $viewModel->invoice->id],
                    ['class' => 'btn btn-ghost-card']
                ) ?>
                <?php if (!empty($viewModel->editableFields)): ?>
                    <button type="submit" class="<?= $btnClass ?>">
                        <?= $btnLabel ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<?= $this->Form->end() ?>

<?php if ($canRegress && empty($viewModel->regressLockMessage)): ?>
<?= $this->element('regress_status_modal', [
    'actionUrl'  => $this->Url->build(['action' => 'regressStatus', $viewModel->invoice->id]),
    'entityNoun' => 'factura',
    'currLabel'  => $currentLabel,
    'prevLabel'  => $viewModel->pipelineLabels[$viewModel->previousStatus] ?? $viewModel->previousStatus,
]) ?>
<?php endif; ?>

<?php if ($viewModel->currentStatus === InvoiceConstants::STATUS_APROBACION && !empty($viewModel->editableFields)): ?>
<?= $this->element('invoice_edit/modify_approvers_modal', ['invoice' => $viewModel->invoice, 'approvers' => $viewModel->approvers]) ?>
<?php endif; ?>

<?php if ($showUploadSection): ?>
<?= $this->element('invoice_edit/upload_doc_modal', ['invoice' => $viewModel->invoice]) ?>
<?php endif; ?>

<?= $this->element('document_row_template', ['showBadge' => true]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>
<?= $this->element('observation_chat_init') ?>

<?= $this->element('invoice_edit/scripts', ['isAdvance' => $isAdvance]) ?>

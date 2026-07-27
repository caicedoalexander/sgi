<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\AdvanceLegalizationViewModel $viewModel
 * @var \App\Model\Entity\User|null $currentUser
 */

use App\Constants\AdvanceConstants;
use App\View\Presentation\AdvancePresentation;

// Reconstituye las variables bare que consume el cuerpo del template desde el
// ViewModel canónico (set('viewModel', $vm) en AdvancesController::legalization()).
[
    'invoice' => $invoice,
    'leg' => $leg,
    'linkedInvoices' => $linkedInvoices,
    'linkedTotal' => $linkedTotal,
    'advanceTotal' => $advanceTotal,
    'diff' => $diff,
    'relationDocument' => $relationDocument,
    'signatureHistory' => $signatureHistory,
    'bankingEntities' => $bankingEntities,
    'surplusPayment' => $surplusPayment,
    'roleName' => $roleName,
    'canRegisterRefund' => $canRegisterRefund,
    'canAuthorizeRefundPayment' => $canAuthorizeRefundPayment,
    'canConfirmRefundPayment' => $canConfirmRefundPayment,
    'approvals' => $approvals,
    'approvalSummary' => $approvalSummary,
    'canManageApprovers' => $canManageApprovers,
    'isAprobacion' => $isAprobacion,
    'approvers' => $approvers,
    'pageTitle' => $pageTitle,
    'legPipelineLabels' => $legPipelineLabels,
    'beneficiary' => $beneficiary,
    'beneficiaryDoc' => $beneficiaryDoc,
    'beneficiaryDocType' => $beneficiaryDocType,
    'beneficiaryKind' => $beneficiaryKind,
    'ps' => $ps,
    'linkedCount' => $linkedCount,
    'diffBadgeClass' => $diffBadgeClass,
    'caseLabels' => $caseLabels,
    'readyForPaymentOptions' => $readyForPaymentOptions,
    'showAccountingCard' => $showAccountingCard,
    'canOperateCurrentStep' => $canOperateCurrentStep,
    'canLinkInvoices' => $canLinkInvoices,
    'canUploadRelationDocument' => $canUploadRelationDocument,
    'canMoveToAprobacion' => $canMoveToAprobacion,
    'canMarkSigned' => $canMarkSigned,
    'canReturnToAprobacion' => $canReturnToAprobacion,
    'canMarkExact' => $canMarkExact,
    'canRegisterShortage' => $canRegisterShortage,
    'canRegisterSurplus' => $canRegisterSurplus,
    'canConfirmShortage' => $canConfirmShortage,
    'canManageDocuments' => $canManageDocuments,
    'childReadiness' => $childReadiness,
    'canResolveDianChildren' => $canResolveDianChildren,
    'canUploadChildSupport' => $canUploadChildSupport,
    'documents' => $documents,
    'totalDocs' => $totalDocs,
] = $viewModel->build();

$this->assign('title', $pageTitle);
?>
<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<?php
// El layout default.php ya expone el CSRF token vía <meta name="csrfToken">.
// Este template lo inyecta directo a las llamadas fetch() inline (ver bloque
// <script> al final). Antes había un <input type="hidden" name="_csrfToken">
// flotante fuera de cualquier <form>, sin lectores en JS — eliminado por
// audit SU-008.
?>

<?php
$legIdLabel = $invoice->invoice_number ?? ('#' . $invoice->id);
?>
<div class="spi-edit-shell">

<?php /* ═══════════════════ HEADER DE PÁGINA (barra fija) ═══════════════════ */ ?>
<div class="spi-edit-shell-head">
<!-- Encabezado de página -->
<div class="spi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="spi-breadcrumb">
            <?= $this->Html->link('Anticipos', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <?= $this->Html->link(h($legIdLabel), ['action' => 'view', $invoice->id]) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Legalización</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="spi-page-title">Legalización del Anticipo</span>
            <span class="spi-edit-id-chip"><?= h($legIdLabel) ?></span>
            <span class="pill <?= h($ps[1]) ?>"><?= h($ps[0]) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-default', 'escape' => false]
        ) ?>
    </div>
</div>
</div><?php /* fin .spi-edit-shell-head */ ?>

<?php
$casePipelineStatuses = AdvanceConstants::PIPELINE_STATUSES_BY_CASE[$leg->case_type ?? '']
    ?? AdvanceConstants::PIPELINE_STATUSES_EXACTO;
$isLegTerminal = $leg->status === AdvanceConstants::STATUS_LEGALIZADA;

// La card de Contabilidad tiene 3 ramas mutuamente excluyentes según $diff.
// Cada una se gatea con su propio predicado del policy.
$caseFlag = abs($diff) < 0.005
    ? $canMarkExact
    : ($diff > 0.005 ? $canRegisterShortage : $canRegisterSurplus);
?>
<div class="spi-edit-shell-body view-anim">

<div class="row gx-3">

    <!-- ═════════════════════ SIDEBAR ═════════════════════ -->
    <aside class="col-lg-3 spi-edit-col d-flex flex-column gap-3">
        <?php
        $registryLines = [];
        $registryLines[] = ['icon' => 'bi-person', 'html' => 'Rol: <strong style="color:var(--text-default);">' . h($roleName) . '</strong>'];
        if ($leg->created) {
            $registryLines[] = ['icon' => 'bi-calendar3', 'html' => 'Iniciada · <span class="mono">' . $leg->created->format('d/m/Y') . '</span>'];
        }
        if ($leg->legalized_at) {
            $registryLines[] = ['icon' => 'bi-check-circle', 'html' => 'Legalizada · <span class="mono">' . date('d/m/Y H:i', strtotime((string)$leg->legalized_at)) . '</span>'];
        }

        // Extra info bajo el monto: diferencia + caso
        ob_start();
        ?>
        <div style="margin-top:14px;display:flex;gap:18px;">
            <div>
                <div class="spi-label" style="font-size:var(--fs-micro);">Vinculado</div>
                <div style="font-size:var(--fs-body-lg);font-weight:700;color:var(--text-default);font-family:var(--font-mono);margin-top:2px;">
                    $ <?= number_format($linkedTotal, 0, ',', '.') ?>
                </div>
            </div>
            <div>
                <div class="spi-label" style="font-size:var(--fs-micro);">Diferencia</div>
                <div style="margin-top:2px;">
                    <span class="pill <?= $diffBadgeClass ?>" style="font-family:var(--font-mono);">
                        $ <?= number_format($diff, 0, ',', '.') ?>
                    </span>
                </div>
            </div>
        </div>
        <?php if ($leg->case_type): ?>
        <div style="margin-top:10px;font-size:11px;color:var(--text-muted);">
            <i class="bi bi-tag" aria-hidden="true"></i>
            Caso: <strong style="color:var(--text-default);"><?= h($caseLabels[$leg->case_type] ?? $leg->case_type) ?></strong>
        </div>
        <?php endif; ?>
        <?php
        $amountExtraHtml = ob_get_clean();

        echo $this->element('pipeline_sidebar', [
            'icon'           => 'clipboard-check',
            'idLabel'        => 'Legalización ' . ($invoice->invoice_number ?? ('#' . $invoice->id)),
            'typeLabel'      => 'Legalización',
            'statusPill'     => $ps[1],
            'statusLabel'    => $ps[0],
            'entityLabel'    => 'Beneficiario (' . $beneficiaryKind . ')',
            'entityValue'    => $beneficiary,
            'entitySubLabel' => trim($beneficiaryDocType . ' ' . ($beneficiaryDoc ?? '')),
            'entitySubIcon'  => 'bi-card-text',
            'amountLabel'    => 'Anticipo',
            'amount'         => (float)$advanceTotal,
            'amountExtraHtml' => $amountExtraHtml,
            'pipelineSteps'  => $casePipelineStatuses,
            'pipelineLabels' => $legPipelineLabels,
            'currentStatus'  => $leg->status,
            'isTerminal'     => $isLegTerminal,
            'modifiedAt'     => $leg->modified ?? null,
            'registryLines'  => $registryLines,
        ]);
        ?>
    </aside>

    <!-- ═════════════════════ CONTENIDO ═════════════════════ -->
    <main class="col-lg-9 spi-edit-col d-flex flex-column gap-3">

        <!-- Sección: Facturas vinculadas -->
        <?= $this->element('advance_legalization/_linked_invoices', [
            'leg' => $leg,
            'invoice' => $invoice,
            'linkedInvoices' => $linkedInvoices,
            'linkedTotal' => $linkedTotal,
            'linkedCount' => $linkedCount,
            'editable' => $canLinkInvoices,
            'readiness' => $childReadiness,
            'canResolveDian' => $canResolveDianChildren,
            'canUploadSupport' => $canUploadChildSupport,
            'uploadModalId' => $canUploadChildSupport ? 'advanceGroupedUploadModal' : null,
        ]) ?>

        <?php if (!$canOperateCurrentStep && !$isLegTerminal): ?>
        <?= $this->element('readonly_banner', [
            'stepLabel' => $legPipelineLabels[$leg->status] ?? $leg->status,
        ]) ?>
        <?php endif; ?>

        <!-- Sección: Acciones del estado -->
        <?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION && $canMoveToAprobacion): ?>
        <div class="spi-card" style="position:relative">
            <div class="accent-strip accent-green"></div>
            <div class="spi-section-head">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>Acción del paso actual
                </span>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?= $this->Form->postLink(
                    '<i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Enviar a aprobación de área',
                    ['action' => 'moveToAprobacion', $leg->advance_invoice_id],
                    ['class' => 'btn btn-primary', 'escape' => false, 'confirm' => '¿Enviar a aprobación de área?']
                ) ?>
                <small class="text-muted">Requiere ≥1 factura vinculada y la relación de facturas adjunta.</small>
            </div>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_APROBACION): ?>
        <?= $this->element('advance_legalization/_approval_panel', [
            'leg' => $leg,
            'invoice' => $invoice,
            'approvals' => $approvals,
            'approvalSummary' => $approvalSummary,
            'canManageApprovers' => $canManageApprovers,
            'approvers' => $approvers,
        ]) ?>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_REVISION_FIRMAS && ($canMarkSigned || $canReturnToAprobacion)): ?>
        <div class="spi-card" style="position:relative">
            <div class="accent-strip accent-green"></div>
            <div class="spi-section-head">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-pen" aria-hidden="true"></i>Acción del paso actual
                </span>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($canMarkSigned): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-check-circle me-1" aria-hidden="true"></i>Marcar como firmado',
                    ['action' => 'markSigned', $leg->advance_invoice_id],
                    ['class' => 'btn btn-primary', 'escape' => false]
                ) ?>
                <?php endif; ?>
                <?php if ($canReturnToAprobacion): ?>
                <button type="button" class="btn btn-ghost-card spi-fg-warning" data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                    <i class="bi bi-arrow-return-left" aria-hidden="true"></i>Devolver a Aprobación
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_CONTABILIDAD && $caseFlag): ?>
        <div class="spi-card" style="position:relative">
            <div class="accent-strip accent-green"></div>
            <div class="spi-section-head">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-calculator" aria-hidden="true"></i>Acción del paso actual
                </span>
            </div>
            <?php if (abs($diff) < 0.005): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'markExact', $leg->advance_invoice_id]]) ?>
            <input type="hidden" name="expected_status" value="<?= h($leg->status) ?>">
            <?= $this->element('advance_legalization/_accounting_fields', [
                'readyForPaymentOptions' => $readyForPaymentOptions,
            ]) ?>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Marcar legalizada (caso exacto)
            </button>
            <?= $this->Form->end() ?>
            <?php elseif ($diff > 0.005): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'registerShortage', $leg->advance_invoice_id]]) ?>
            <input type="hidden" name="expected_status" value="<?= h($leg->status) ?>">
            <?= $this->element('advance_legalization/_accounting_fields', [
                'readyForPaymentOptions' => $readyForPaymentOptions,
            ]) ?>
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="input-label">Monto del faltante (consignación pendiente)</label>
                    <input type="text" name="shortage_amount" class="form-control currency-input"
                           value="<?= (int)round($diff) ?>" required>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-arrow-down-circle me-1" aria-hidden="true"></i>Registrar faltante
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
            <?php else: ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'registerSurplus', $leg->advance_invoice_id]]) ?>
            <input type="hidden" name="expected_status" value="<?= h($leg->status) ?>">
            <?= $this->element('advance_legalization/_accounting_fields', [
                'readyForPaymentOptions' => $readyForPaymentOptions,
            ]) ?>
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="input-label">Monto del sobrante (reintegro a beneficiario)</label>
                    <input type="text" name="surplus_amount" class="form-control currency-input"
                           value="<?= (int)round(abs($diff)) ?>" required>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-danger w-100">
                        <i class="bi bi-arrow-up-circle me-1" aria-hidden="true"></i>Registrar sobrante
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
            <?php endif; ?>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_TESORERIA && $leg->case_type === AdvanceConstants::CASE_FALTANTE && $canConfirmShortage): ?>
        <div class="spi-card" style="position:relative">
            <div class="accent-strip accent-green"></div>
            <div class="spi-section-head">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-bank" aria-hidden="true"></i>Acción del paso actual
                </span>
            </div>
            <div class="d-flex flex-column gap-1 mb-3">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-bank" aria-hidden="true"></i>Confirmar consignación
                </span>
                <div class="hr"></div>
                <div class="banner info">
                    <div class="banner-icon"><i class="bi bi-info-circle-fill" aria-hidden="true"></i></div>
                    <div class="banner-body">
                        <div class="banner-msg">
                            Monto pendiente:
                            <strong style="color:var(--text-default);">$ <?= $this->Number->format((float)$leg->shortage_amount, ['places' => 2]) ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <form id="confirm-shortage-form"
                  data-shortage-url="<?= $this->Url->build(['action' => 'confirmShortage', $leg->advance_invoice_id]) ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="input-label">N.º comprobante *</label>
                    <input type="text" name="receipt_number" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="input-label">Fecha</label>
                    <input type="text" name="received_at" class="form-control flatpickr-date" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="d-flex justify-content-end mt-3">
                <button type="submit" id="confirm-shortage-btn" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Confirmar consignación
                </button>
            </div>
            </form>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_TESORERIA && $leg->case_type === AdvanceConstants::CASE_SOBRANTE): ?>
        <div class="spi-card">
        <?= $this->element('payment_section', [
            'payments' => [],
            'bankingEntities' => $bankingEntities,
            'addPaymentUrl' => ['controller' => 'Advances', 'action' => 'registerRefund', $leg->advance_invoice_id],
            'paymentStatus' => null,
            'totalAmount' => (float)$leg->surplus_amount,
            'mode' => 'tesoreria_register',
            'canRegisterPayment' => $canRegisterRefund,
            'canAuthorize' => false,
            'canDelete' => false,
            'forceFullAmount' => true,
            'singlePaymentOnly' => true,
            'sectionTitle' => 'Reintegro al beneficiario',
            'sectionIcon' => 'bi-arrow-up-circle',
        ]) ?>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_AUTORIZACION_PAGO && $leg->case_type === AdvanceConstants::CASE_SOBRANTE): ?>
        <div class="spi-card">
        <?= $this->element('payment_section', [
            'payments' => $surplusPayment ? [$surplusPayment] : [],
            'bankingEntities' => $bankingEntities,
            'addPaymentUrl' => ['controller' => 'Advances', 'action' => 'registerRefund', $leg->advance_invoice_id],
            'authorizeUrlFn' => fn($pId) => ['controller' => 'AdvanceRefundPayments', 'action' => 'authorizePayment', $invoice->id, $pId],
            'rejectUrlFn'    => fn($pId) => ['controller' => 'AdvanceRefundPayments', 'action' => 'rejectPayment', $invoice->id, $pId],
            'paymentStatus' => null,
            'totalAmount' => (float)$leg->surplus_amount,
            'mode' => 'authorize',
            'canRegisterPayment' => false,
            'canAuthorize' => $canAuthorizeRefundPayment,
            'canDelete' => false,
            'rejectMessage' => '¿Rechazar el reintegro? La legalización volverá a Tesorería para nuevo registro.',
            'sectionTitle' => 'Autorización de Reintegro',
            'sectionIcon' => 'bi-shield-check',
        ]) ?>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_VERIFICACION_PAGO && $leg->case_type === AdvanceConstants::CASE_SOBRANTE): ?>
        <div class="spi-card">
        <?= $this->element('payment_section', [
            'payments' => $surplusPayment ? [$surplusPayment] : [],
            'bankingEntities' => $bankingEntities,
            'addPaymentUrl' => ['controller' => 'Advances', 'action' => 'registerRefund', $leg->advance_invoice_id],
            'paymentStatus' => null,
            'totalAmount' => (float)$leg->surplus_amount,
            'mode' => 'view',
            'canRegisterPayment' => false,
            'canAuthorize' => false,
            'canDelete' => false,
            'sectionTitle' => 'Reintegro autorizado',
            'sectionIcon' => 'bi-shield-check',
        ]) ?>
        </div>
        <?= $this->element('confirm_payment_card', [
            'isVerificacionPago' => true,
            'canConfirm' => $canConfirmRefundPayment ?? false,
            'confirmUrl' => ['controller' => 'AdvanceRefundPayments', 'action' => 'confirmPayment', $leg->advance_invoice_id],
            'message' => 'El reintegro fue autorizado por el Contador. Confirme cuando el dinero haya salido del banco para cerrar la legalización.',
        ]) ?>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_LEGALIZADA): ?>
        <div class="banner">
            <div class="banner-icon" style="background:var(--primary-soft);color:var(--primary-color);">
                <i class="bi bi-check-circle" aria-hidden="true"></i>
            </div>
            <div class="banner-body">
                <div class="banner-msg">
                    <strong>Legalizada</strong>
                    <?php if ($leg->legalized_at): ?> el <?= h(date('d/m/Y H:i', strtotime((string)$leg->legalized_at))) ?><?php endif; ?>
                    <?php if ($leg->case_type): ?> — caso <strong><?= h($caseLabels[$leg->case_type] ?? $leg->case_type) ?></strong>.<?php else: ?>.<?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($showAccountingCard): ?>
        <div class="spi-card">
            <div class="spi-section-head">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-calculator" aria-hidden="true"></i>Causación
                </span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
                <div>
                    <div class="field-row">
                        <span class="k">Causada</span>
                        <span class="v"><?= $leg->accrued ? 'Sí' : 'No' ?></span>
                    </div>
                    <div class="field-row is-last">
                        <span class="k">Fecha de Causación</span>
                        <span class="v mono"><?= h($leg->accrual_date?->format('d/m/Y') ?? '—') ?></span>
                    </div>
                </div>
                <div>
                    <div class="field-row is-last">
                        <span class="k">Lista para Pago</span>
                        <span class="v"><?= h($leg->ready_for_payment ?? '—') ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    <!-- Soportes -->
    <?= $this->element('advance_legalization/_soportes', [
        'leg' => $leg,
        'relationDocument' => $relationDocument,
        'signatureHistory' => $signatureHistory,
        'editable' => $canUploadRelationDocument,
        'documents' => $documents,
        'totalDocs' => $totalDocs,
        'canManageDocuments' => $canManageDocuments,
    ]) ?>
    </main>
</div><?php /* fin .row */ ?>
</div><?php /* fin .spi-edit-shell-body */ ?>

<?php /* ═══════════════════ FOOTER (barra fija) ═══════════════════ */ ?>
<div class="spi-edit-footer">
    <div class="spi-edit-footer-meta">
        <span class="d-inline-flex align-items-center gap-1">
            <i class="bi bi-person spi-fg-faint" aria-hidden="true"></i>
            Rol: <strong style="color:var(--text-default);"><?= h($roleName) ?></strong>
        </span>
        <?php if ($leg->modified): ?>
        <span class="sep"></span>
        <span class="d-inline-flex align-items-center gap-1">
            <i class="bi bi-clock spi-fg-faint" aria-hidden="true"></i>
            Última modificación: <span class="mono"><?= $leg->modified->format('d/m/Y H:i') ?></span>
        </span>
        <?php endif; ?>
    </div>
</div>

</div><?php /* fin .spi-edit-shell */ ?>

<?= $this->element('observations/drawer', [
    'observations'    => $invoice->invoice_observations ?? [],
    'count'           => count($invoice->invoice_observations ?? []),
    'formUrl'         => ['controller' => 'Invoices', 'action' => 'addObservation', $invoice->id],
    'currentUserName' => $currentUser->full_name ?? ($currentUser->username ?? 'Usuario'),
]) ?>

<?php if ($leg && $leg->status === AdvanceConstants::STATUS_VALIDACION && $canLinkInvoices): ?>
<?php
// Shell del modal — el contenido se carga vía AJAX al abrir (audit SU-003).
// El JS global en spi-common.js intercepta `show.bs.modal` cuando el modal
// declara data-load-url, hace fetch del fragment y reemplaza .modal-content.
$linkCandidatesUrl = $this->Url->build([
    'controller' => 'Advances',
    'action' => 'linkCandidates',
    $leg->advance_invoice_id,
]);
?>
<div class="modal fade" id="advanceLinkModal" tabindex="-1"
     data-load-url="<?= h($linkCandidatesUrl) ?>">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-body text-center py-5 text-muted modal-loading-state">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                Cargando facturas disponibles...
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($leg && $leg->status === AdvanceConstants::STATUS_REVISION_FIRMAS && $canReturnToAprobacion): ?>
<?= $this->element('regress_status_modal', [
    'actionUrl'  => $this->Url->build(['action' => 'returnToAprobacion', $leg->advance_invoice_id]),
    'entityNoun' => 'legalización',
    'currLabel'  => $legPipelineLabels[AdvanceConstants::STATUS_REVISION_FIRMAS] ?? 'Revisión y Firmas',
    'prevLabel'  => $legPipelineLabels[AdvanceConstants::STATUS_APROBACION] ?? 'Aprobación',
]) ?>
<?php endif; ?>

<?php if ($canManageDocuments): ?>
<?= $this->element('upload_doc_modal', [
    'modalId'   => 'uploadLegDocModal',
    'uploadUrl' => $this->Url->build(['action' => 'uploadLegalizationDocument', $leg->advance_invoice_id]),
    'showDocumentType' => false,
]) ?>
<?= $this->element('document_row_template', ['showBadge' => false]) ?>
<?= $this->Html->script('spi-document-uploader', ['block' => true]) ?>
<?php $this->append('script') ?>
<script>
(function(){
    SpiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.spi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadLegDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>
<?php $this->end() ?>
<?php endif; ?>

<?php // Modal compartido para subir soporte a una hija vinculada; el JS fija su URL por fila. ?>
<?php if ($canUploadChildSupport): ?>
<?= $this->element('upload_doc_modal', [
    'modalId' => 'advanceGroupedUploadModal',
    'uploadUrl' => '', // la fija SpiGroupedInvoices por fila (data-url del form)
    'formId' => 'grouped-upload-form',
    'showDocumentType' => true,
]) ?>
<?php endif; ?>

<?php $this->append('script') ?>
<script>
(function () {
    var csrfToken = <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>;

    function showRelDocToast(message) {
        if (!window.bootstrap || !window.bootstrap.Toast) { alert(message); return; }
        var c = document.getElementById('spi-toast-container');
        if (!c) {
            c = document.createElement('div');
            c.id = 'spi-toast-container';
            c.className = 'toast-container position-fixed top-0 end-0 p-3';
            c.style.zIndex = '1090';
            document.body.appendChild(c);
        }
        var el = document.createElement('div');
        el.className = 'toast align-items-center text-bg-danger border-0';
        el.setAttribute('role', 'alert');
        el.innerHTML = '<div class="d-flex"><div class="toast-body"></div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
        el.querySelector('.toast-body').textContent = message;
        c.appendChild(el);
        var t = new window.bootstrap.Toast(el, { delay: 4500 });
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
        t.show();
    }

    var shortageForm = document.getElementById('confirm-shortage-form');
    if (shortageForm) {
        shortageForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('confirm-shortage-btn');
            var originalHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                btn.disabled = true;
            }
            var fd = new FormData(shortageForm);
            fetch(shortageForm.dataset.shortageUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                    'Accept': 'application/json',
                },
                body: fd,
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    if (data.redirect) { window.location = data.redirect; }
                    else { window.location.reload(); }
                } else {
                    showRelDocToast(data.error || 'Error al confirmar consignación.');
                    if (btn) { btn.innerHTML = originalHtml; btn.disabled = false; }
                }
            })
            .catch(function () {
                showRelDocToast('Error de conexión. Intente nuevamente.');
                if (btn) { btn.innerHTML = originalHtml; btn.disabled = false; }
            });
        });
    }

    document.querySelectorAll('[data-rel-doc-trigger]').forEach(function (input) {
        input.addEventListener('change', function () {
            if (!input.files.length) return;
            var form = input.closest('form[data-upload-url]');
            if (!form) return;

            var label = form.querySelector('label');
            var originalHtml = label ? label.innerHTML : '';
            if (label) {
                label.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                label.style.pointerEvents = 'none';
            }

            var fd = new FormData();
            fd.append('relation_document', input.files[0]);

            fetch(form.dataset.uploadUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                    'Accept': 'application/json',
                },
                body: fd,
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    showRelDocToast(data.error || 'Error al subir el documento.');
                    input.value = '';
                    if (label) { label.innerHTML = originalHtml; label.style.pointerEvents = ''; }
                }
            })
            .catch(function () {
                showRelDocToast('Error de conexión. Intente nuevamente.');
                input.value = '';
                if (label) { label.innerHTML = originalHtml; label.style.pointerEvents = ''; }
            });
        });
    });
})();
</script>
<?php $this->end() ?>

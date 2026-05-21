<?php
/**
 * El controller pasa los datos via $this->set(\$vm->build()),
 * desempaquetando AdvanceLegalizationViewModel en variables individuales.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var \App\Model\Entity\AdvanceLegalization $leg
 * @var \Cake\Collection\CollectionInterface<\App\Model\Entity\Invoice> $linkedInvoices
 * @var float $linkedTotal
 * @var float $advanceTotal
 * @var float $diff
 * @var \App\Model\Entity\AdvanceLegalizationSignature|null $relationDocument
 * @var array<\App\Model\Entity\AdvanceLegalizationSignature> $signatureHistory
 * @var array $bankingEntities
 * @var \App\Model\Entity\InvoicePayment|null $surplusPayment
 * @var string $roleName
 * @var bool $canRegisterRefund
 * @var bool $canAuthorizeRefundPayment
 * @var bool $canConfirmRefundPayment
 * @var \App\Model\Entity\User|null $currentUser
 *
 * Derivaciones de presentación (desde el ViewModel):
 * @var string $pageTitle
 * @var array<string,string> $legPipelineLabels
 * @var string $beneficiary
 * @var string|null $beneficiaryDoc
 * @var string $beneficiaryDocType
 * @var string $beneficiaryKind
 * @var array{0:string,1:string} $ps
 * @var int $linkedCount
 * @var string $diffBadgeClass
 * @var array<string,string> $caseLabels
 */

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\View\Presentation\AdvancePresentation;
use App\View\Presentation\InvoicePresentation;

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
<!-- Encabezado de página -->
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Anticipos', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <?= $this->Html->link(h($legIdLabel), ['action' => 'view', $invoice->id]) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Legalización</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title">Legalización del Anticipo</span>
            <span class="sgi-edit-id-chip"><?= h($legIdLabel) ?></span>
            <span class="pill <?= h($ps[1]) ?>"><?= h($ps[0]) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
    </div>
</div>

<?php
$casePipelineStatuses = AdvanceConstants::PIPELINE_STATUSES_BY_CASE[$leg->case_type ?? '']
    ?? AdvanceConstants::PIPELINE_STATUSES_EXACTO;
$isLegTerminal = $leg->status === AdvanceConstants::STATUS_LEGALIZADA;
?>
<div class="sgi-invoice-view-grid view-anim">

    <!-- ═════════════════════ SIDEBAR ═════════════════════ -->
    <aside class="sgi-invoice-view-left">
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
                <div class="sgi-label" style="font-size:var(--fs-micro);">Vinculado</div>
                <div style="font-size:var(--fs-body-lg);font-weight:700;color:var(--text-default);font-family:var(--font-mono);margin-top:2px;">
                    $ <?= number_format($linkedTotal, 0, ',', '.') ?>
                </div>
            </div>
            <div>
                <div class="sgi-label" style="font-size:var(--fs-micro);">Diferencia</div>
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
    <main class="sgi-invoice-view-right">
    <div class="card" style="padding:20px;">

        <!-- Sección: Facturas vinculadas -->
        <?php $liGrid = 'display:grid;grid-template-columns:1.1fr 1.8fr 0.9fr 1fr 1.2fr 32px;gap:12px;align-items:center;'; ?>
        <div class="mb-4">
            <div class="d-flex align-items-center justify-content-between" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-link-45deg" aria-hidden="true"></i>Facturas vinculadas
                </span>
                <?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
                <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#advanceLinkModal">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>Vincular
                </button>
                <?php endif; ?>
            </div>

            <?php if ($linkedCount === 0): ?>
            <div class="empty-state">
                <div class="es-icon es-icon-neutral">
                    <i class="bi bi-inbox" aria-hidden="true"></i>
                </div>
                <div class="es-title">Sin facturas vinculadas</div>
            </div>
            <?php else: ?>
            <div class="sgi-card" style="padding:0;">
                <div style="<?= $liGrid ?>padding:9px 14px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.6px;text-transform:uppercase;" role="row">
                    <span># Factura</span>
                    <span>Beneficiario</span>
                    <span>Fecha</span>
                    <span style="text-align:right;">Monto</span>
                    <span>Estado</span>
                    <span aria-hidden="true"></span>
                </div>
                <?php foreach ($linkedInvoices as $idx => $li): ?>
                <div class="clickable-row" role="row"
                     data-href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $li->id]) ?>"
                     style="<?= $liGrid ?>padding:11px 14px;background:#fff;cursor:pointer;<?= $idx > 0 ? 'border-top:1px solid var(--rule);' : '' ?>">
                    <span class="mono" style="font-size:12px;font-weight:700;color:var(--text-strong);">
                        <?= h($li->invoice_number ?: '#' . $li->id) ?>
                    </span>
                    <span style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($li->provider->name ?? ($li->employee->full_name ?? '—')) ?>
                    </span>
                    <span class="mono" style="font-size:11.5px;color:var(--text-muted);">
                        <?= $li->issue_date?->format('d/m/Y') ?? '—' ?>
                    </span>
                    <span class="mono" style="text-align:right;font-size:12.5px;font-weight:700;color:var(--text-default);">
                        $ <?= number_format((float)$li->amount, 0, ',', '.') ?>
                    </span>
                    <span>
                        <span class="pill <?= InvoicePresentation::STATUS_BADGES[$li->pipeline_status] ?? 'pill-muted' ?> pill-sm">
                            <?= h(strtoupper(InvoiceConstants::STATUS_LABELS[$li->pipeline_status] ?? $li->pipeline_status)) ?>
                        </span>
                    </span>
                    <span style="display:flex;justify-content:flex-end;">
                        <?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
                        <?= $this->Form->postLink(
                            '<i class="bi bi-x-lg" aria-hidden="true"></i>',
                            ['action' => 'unlinkInvoice', $invoice->id, $li->id],
                            ['class' => 'btn-icon', 'escape' => false, 'confirm' => '¿Desvincular esta factura?', 'title' => 'Desvincular']
                        ) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <div style="<?= $liGrid ?>padding:10px 14px;background:var(--bg-subtle);border-top:1px solid var(--rule);">
                    <span style="grid-column:1 / 4;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;">
                        Total vinculado
                    </span>
                    <span class="mono" style="text-align:right;font-size:12.5px;font-weight:700;color:var(--primary-color);">
                        $ <?= number_format($linkedTotal, 0, ',', '.') ?>
                    </span>
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sección: Acciones del estado -->
        <?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
        <div class="sgi-stage-actions">
            <div class="sgi-stage-actions-head">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>Acción del paso actual
                </span>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?= $this->Form->postLink(
                    '<i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Pasar a Revisión y Firmas',
                    ['action' => 'moveToRevision', $leg->advance_invoice_id],
                    ['class' => 'btn btn-primary', 'escape' => false, 'confirm' => '¿Pasar a Revisión y Firmas?']
                ) ?>
                <small class="text-muted">Requiere ≥1 factura vinculada y la relación de facturas adjunta.</small>
            </div>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_REVISION_FIRMAS): ?>
        <div class="sgi-stage-actions">
            <div class="sgi-stage-actions-head">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-pen" aria-hidden="true"></i>Acción del paso actual
                </span>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?= $this->Form->postLink(
                    '<i class="bi bi-check-circle me-1" aria-hidden="true"></i>Marcar como firmado',
                    ['action' => 'markSigned', $leg->advance_invoice_id],
                    ['class' => 'btn btn-primary', 'escape' => false]
                ) ?>
                <button type="button" class="btn btn-ghost-card sgi-fg-warning" data-bs-toggle="modal" data-bs-target="#advReturnModal">
                    <i class="bi bi-arrow-return-left" aria-hidden="true"></i>Devolver a Validación
                </button>
            </div>
        </div>

        <div class="modal fade" id="advReturnModal" tabindex="-1">
            <div class="modal-dialog">
                <?= $this->Form->create(null, ['url' => ['action' => 'returnToValidacion', $leg->advance_invoice_id]]) ?>
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Devolver a Validación</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Motivo *</label>
                        <?= $this->Form->control('reason', ['type' => 'textarea', 'rows' => 3, 'class' => 'form-control', 'required' => true, 'label' => false]) ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-warning">Devolver</button>
                    </div>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_CONTABILIDAD): ?>
        <div class="sgi-stage-actions">
            <div class="sgi-stage-actions-head">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-calculator" aria-hidden="true"></i>Acción del paso actual
                </span>
            </div>
            <?php if (abs($diff) < 0.005): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-check-circle me-1" aria-hidden="true"></i>Marcar legalizada (caso exacto)',
                ['action' => 'markExact', $leg->advance_invoice_id],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
            <?php elseif ($diff > 0.005): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'registerShortage', $leg->advance_invoice_id]]) ?>
            <input type="hidden" name="expected_status" value="<?= h($leg->status) ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Monto del faltante (consignación pendiente)</label>
                    <input type="text" name="shortage_amount" class="form-control currency-input"
                           value="<?= number_format($diff, 0, ',', '.') ?>" required>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="bi bi-arrow-down-circle me-1" aria-hidden="true"></i>Registrar faltante
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
            <?php else: ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'registerSurplus', $leg->advance_invoice_id]]) ?>
            <input type="hidden" name="expected_status" value="<?= h($leg->status) ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Monto del sobrante (reintegro a beneficiario)</label>
                    <input type="text" name="surplus_amount" class="form-control currency-input"
                           value="<?= number_format(abs($diff), 0, ',', '.') ?>" required>
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
        <?php elseif ($leg->status === AdvanceConstants::STATUS_TESORERIA && $leg->case_type === AdvanceConstants::CASE_FALTANTE): ?>
        <div class="sgi-stage-actions">
            <div class="sgi-stage-actions-head">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-bank" aria-hidden="true"></i>Acción del paso actual
                </span>
            </div>
            <div class="d-flex flex-column gap-1 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="text-uppercase fw-semibold flex-shrink-0"
                          style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">
                        <i class="bi bi-bank me-1" aria-hidden="true"></i>Confirmar consignación
                    </span>
                    <div style="flex:1;height:1px;background:var(--border-color);"></div>
                </div>
                <div class="d-flex align-items-center gap-2"
                     style="border-left:2px solid var(--secondary-color);padding:.35rem .7rem;">
                    <i class="bi bi-info-circle-fill flex-shrink-0"
                       style="color:var(--secondary-color);font-size:.85rem;" aria-hidden="true"></i>
                    <span style="font-size:var(--fs-body-sm);color:var(--text-muted);">Monto pendiente:</span>
                    <strong style="color:var(--text-default);font-size:.85rem;letter-spacing:-.01em;">
                        $ <?= $this->Number->format((float)$leg->shortage_amount, ['places' => 2]) ?>
                    </strong>
                </div>
            </div>

            <form id="confirm-shortage-form"
                  data-shortage-url="<?= $this->Url->build(['action' => 'confirmShortage', $leg->advance_invoice_id]) ?>"
                  enctype="multipart/form-data">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">N.º comprobante *</label>
                    <input type="text" name="receipt_number" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha</label>
                    <input type="text" name="received_at" class="form-control flatpickr-date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Soporte (PDF / imagen)</label>
                    <input type="file" name="receipt_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
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
        <?php elseif ($leg->status === AdvanceConstants::STATUS_AUTORIZACION_PAGO && $leg->case_type === AdvanceConstants::CASE_SOBRANTE): ?>
        <?= $this->element('payment_section', [
            'payments' => $surplusPayment ? [$surplusPayment] : [],
            'bankingEntities' => $bankingEntities,
            'addPaymentUrl' => ['controller' => 'Advances', 'action' => 'registerRefund', $leg->advance_invoice_id],
            'authorizeUrlFn' => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'authorizePayment', $invoice->id, $pId],
            'rejectUrlFn'    => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'rejectPayment', $invoice->id, $pId],
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
        <?php elseif ($leg->status === AdvanceConstants::STATUS_VERIFICACION_PAGO && $leg->case_type === AdvanceConstants::CASE_SOBRANTE): ?>
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
        <?= $this->element('confirm_payment_card', [
            'isVerificacionPago' => true,
            'canConfirm' => $canConfirmRefundPayment ?? false,
            'confirmUrl' => ['controller' => 'Advances', 'action' => 'confirmRefundPayment', $leg->advance_invoice_id],
            'message' => 'El reintegro fue autorizado por el Contador. Confirme cuando el dinero haya salido del banco para cerrar la legalización.',
        ]) ?>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_LEGALIZADA): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 mb-0">
            <i class="bi bi-check-circle-fill fs-5" aria-hidden="true"></i>
            <span>
                <strong>Legalizada</strong>
                <?php if ($leg->legalized_at): ?> el <?= h(date('d/m/Y H:i', strtotime((string)$leg->legalized_at))) ?><?php endif; ?>
                <?php if ($leg->case_type): ?> — caso <strong><?= h($caseLabels[$leg->case_type] ?? $leg->case_type) ?></strong><?php endif; ?>.
            </span>
        </div>
        <?php endif; ?>

    </div><!-- /card interior -->

    <!-- Soportes -->
    <div class="sgi-card d-flex flex-column">
        <div class="d-flex align-items-center" style="margin-bottom:12px;">
            <span class="sgi-label d-inline-flex align-items-center gap-2">
                <i class="bi bi-paperclip" aria-hidden="true"></i>
                Soportes
            </span>
        </div>

        <div style="max-height:420px;overflow-y:auto;">

        <!-- Documento especial: Relación de facturas -->
        <div class="d-flex align-items-center gap-2" style="padding:.3rem .5rem;background:var(--bg-subtle);">
            <span class="pill pill-primary-soft">Relación de facturas</span>
        </div>
        <?php if ($relationDocument): ?>
        <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
            <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                <i class="bi <?= h($this->DocumentIcon->iconClass($relationDocument->mime_type ?? null)) ?>"
                   style="color:<?= h($this->DocumentIcon->iconColor($relationDocument->mime_type ?? null)) ?>;font-size:18px;" aria-hidden="true"></i>
            </div>
            <div class="grow">
                <div title="<?= h($relationDocument->file_name ?? '') ?>"
                     style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($relationDocument->file_name ?? 'Documento') ?>
                </div>
                <div class="row-flex gap-6" style="margin-top:4px;flex-wrap:wrap;">
                    <?php if ($relationDocument->isSigned()): ?>
                    <span class="pill pill-primary-soft pill-sm">
                        <i class="bi bi-check-circle" aria-hidden="true"></i>Firmado<?php if ($relationDocument->signed_by_user): ?> · <?= h($relationDocument->signed_by_user->full_name ?? '') ?><?php endif; ?>
                    </span>
                    <?php else: ?>
                    <span class="pill pill-warning-soft pill-sm">
                        <i class="bi bi-clock" aria-hidden="true"></i>Pendiente de firma
                    </span>
                    <?php endif; ?>
                    <?php if ($relationDocument->created): ?>
                    <span class="mono sgi-body-faint" style="font-size:var(--fs-label);">
                        <?= $relationDocument->created->format('d/m/Y H:i') ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row-flex gap-4" style="flex-shrink:0;">
                <?php if (in_array($leg->status, [AdvanceConstants::STATUS_VALIDACION, AdvanceConstants::STATUS_REVISION_FIRMAS], true)): ?>
                <form id="rel-doc-update-form" class="d-inline"
                      data-upload-url="<?= $this->Url->build(['action' => 'uploadRelationDocument', $leg->advance_invoice_id]) ?>">
                <input type="file" name="relation_document" id="rel-doc-file-update" required
                       accept=".pdf,.jpg,.jpeg,.png" style="display:none;" data-rel-doc-trigger>
                <label for="rel-doc-file-update" class="btn-icon" style="cursor:pointer;" title="Reemplazar">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                </label>
                </form>
                <?php endif; ?>
                <?php if (!empty($relationDocument->file_path)): ?>
                <?= $this->Html->link(
                    '<i class="bi bi-eye" aria-hidden="true"></i>',
                    '/' . $relationDocument->file_path,
                    ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Abrir']
                ) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
        <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
            <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                <i class="bi bi-file-earmark-x" style="color:var(--text-disabled);font-size:18px;" aria-hidden="true"></i>
            </div>
            <div class="grow">
                <span class="sgi-body-faint" style="font-size:var(--fs-body-sm);">Sin documento adjunto</span>
            </div>
            <form id="rel-doc-upload-form" class="d-inline flex-shrink-0"
                  data-upload-url="<?= $this->Url->build(['action' => 'uploadRelationDocument', $leg->advance_invoice_id]) ?>">
            <input type="file" name="relation_document" id="rel-doc-file-new" required
                   accept=".pdf,.jpg,.jpeg,.png" style="display:none;" data-rel-doc-trigger>
            <label for="rel-doc-file-new" class="btn btn-default btn-sm" style="cursor:pointer;" title="Subir">
                <i class="bi bi-upload" aria-hidden="true"></i>Subir
            </label>
            </form>
        </div>
        <?php else: ?>
        <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
            <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                <i class="bi bi-file-earmark-x" style="color:var(--text-disabled);font-size:18px;" aria-hidden="true"></i>
            </div>
            <div class="grow">
                <span class="sgi-body-faint" style="font-size:var(--fs-body-sm);">Sin documento</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Documento especial: Comprobante de consignación (caso faltante) -->
        <?php if ($leg->case_type === AdvanceConstants::CASE_FALTANTE && $leg->shortage_receipt_path): ?>
        <div class="d-flex align-items-center gap-2" style="padding:.3rem .5rem;background:var(--bg-subtle);margin-top:.5rem;">
            <span class="pill pill-orange-soft">Comprobante de consignación</span>
        </div>
        <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
            <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                <i class="bi bi-file-earmark-pdf" style="color:var(--danger-color);font-size:18px;" aria-hidden="true"></i>
            </div>
            <div class="grow">
                <div style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($leg->shortage_receipt_number ?: 'Comprobante') ?>
                </div>
                <?php if ($leg->shortage_received_at): ?>
                <div class="mono sgi-body-faint" style="font-size:var(--fs-label);margin-top:4px;">
                    <?= h(date('d/m/Y', strtotime((string)$leg->shortage_received_at))) ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="row-flex gap-4" style="flex-shrink:0;">
                <?= $this->Html->link(
                    '<i class="bi bi-eye" aria-hidden="true"></i>',
                    '/' . $leg->shortage_receipt_path,
                    ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Abrir']
                ) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Documento especial: Historial de firmas rechazadas -->
        <?php if (!empty($signatureHistory)): ?>
        <div class="d-flex align-items-center gap-2" style="padding:.3rem .5rem;background:var(--bg-subtle);margin-top:.5rem;">
            <span class="pill pill-muted">Historial de firmas</span>
        </div>
        <?php foreach ($signatureHistory as $sig): ?>
        <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;opacity:.7;">
            <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
                <i class="bi <?= h($this->DocumentIcon->iconClass($sig->mime_type ?? null)) ?>"
                   style="color:var(--text-faint);font-size:18px;" aria-hidden="true"></i>
            </div>
            <div class="grow">
                <div title="<?= h($sig->file_name ?? '') ?>"
                     style="font-size:var(--fs-body);font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($sig->file_name ?? '—') ?>
                </div>
                <div class="row-flex gap-6" style="margin-top:4px;flex-wrap:wrap;">
                    <span class="pill pill-danger-soft pill-sm">Rechazado</span>
                    <?php if ($sig->rejection_reason): ?>
                    <span class="sgi-body-faint" style="font-size:var(--fs-label);"><?= h($sig->rejection_reason) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row-flex gap-4" style="flex-shrink:0;">
                <?php if (!empty($sig->file_path)): ?>
                <?= $this->Html->link(
                    '<i class="bi bi-eye" aria-hidden="true"></i>',
                    '/' . $sig->file_path,
                    ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Abrir']
                ) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$relationDocument && empty($signatureHistory) && !$leg->shortage_receipt_path): ?>
        <div class="empty-state">
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-paperclip" aria-hidden="true"></i>
            </div>
            <div class="es-title">Sin soportes adjuntos</div>
        </div>
        <?php endif; ?>

        </div>
    </div>
    </main>
</div><!-- /sgi-invoice-view-grid -->

<?= $this->element('observations/drawer', [
    'observations'    => $invoice->invoice_observations ?? [],
    'count'           => count($invoice->invoice_observations ?? []),
    'formUrl'         => ['controller' => 'Invoices', 'action' => 'addObservation', $invoice->id],
    'currentUserName' => $currentUser->full_name ?? ($currentUser->username ?? 'Usuario'),
]) ?>

<?php if ($leg && $leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
<?php
// Shell del modal — el contenido se carga vía AJAX al abrir (audit SU-003).
// El JS global en sgi-common.js intercepta `show.bs.modal` cuando el modal
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

<?php $this->append('script') ?>
<script>
(function () {
    var csrfToken = <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>;

    function showRelDocToast(message) {
        if (!window.bootstrap || !window.bootstrap.Toast) { alert(message); return; }
        var c = document.getElementById('sgi-toast-container');
        if (!c) {
            c = document.createElement('div');
            c.id = 'sgi-toast-container';
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
                    window.location.reload();
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

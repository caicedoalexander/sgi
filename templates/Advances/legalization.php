<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var \App\Model\Entity\AdvanceLegalization $leg
 * @var iterable<\App\Model\Entity\Invoice> $linkedInvoices
 * @var float $linkedTotal
 * @var float $advanceTotal
 * @var float $diff
 * @var \App\Model\Entity\AdvanceLegalizationSignature|null $relationDocument
 * @var array<\App\Model\Entity\AdvanceLegalizationSignature> $signatureHistory
 */

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;

$this->assign('title', 'Legalización Anticipo #' . $invoice->id);

$legBadgeMap = [
    AdvanceConstants::STATUS_VALIDACION       => ['Validación', 'bg-info text-dark'],
    AdvanceConstants::STATUS_REVISION_FIRMAS  => ['Revisión y Firmas', 'bg-primary'],
    AdvanceConstants::STATUS_CONTABILIDAD     => ['Contabilidad', 'bg-warning text-dark'],
    AdvanceConstants::STATUS_TESORERIA        => ['Tesorería', 'bg-warning text-dark'],
    AdvanceConstants::STATUS_LEGALIZADA       => ['Legalizada', 'bg-success'],
];
$legPipelineLabels = AdvanceConstants::STATUS_LABELS;

$beneficiary = $invoice->provider->name ?? ($invoice->employee->full_name ?? '—');
$beneficiaryDoc = $invoice->provider->document_number ?? ($invoice->employee->document_number ?? null);
$beneficiaryDocType = $invoice->provider_id
    ? ($invoice->provider->document_type ?? '')
    : ($invoice->employee_id ? ($invoice->employee->document_type ?? '') : '');
$beneficiaryKind = $invoice->provider_id ? 'Proveedor' : ($invoice->employee_id ? 'Empleado' : '—');

$ps = $legBadgeMap[$leg->status] ?? ['Desconocido', 'bg-dark'];

$linkedCount = is_object($linkedInvoices) && method_exists($linkedInvoices, 'count')
    ? $linkedInvoices->count()
    : count((array)$linkedInvoices);

$diffBadgeClass = abs($diff) < 0.005 ? 'bg-success' : ($diff > 0 ? 'bg-warning text-dark' : 'bg-danger');

$caseLabels = [
    AdvanceConstants::CASE_EXACTO => 'Exacto',
    AdvanceConstants::CASE_FALTANTE => 'Faltante',
    AdvanceConstants::CASE_SOBRANTE => 'Sobrante',
];

$docIcon = fn(?string $mime): string => match (true) {
    str_contains($mime ?? '', 'pdf') => 'bi-file-earmark-pdf',
    str_contains($mime ?? '', 'image') => 'bi-file-earmark-image',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => 'bi-file-earmark-word',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'bi-file-earmark-excel',
    default => 'bi-file-earmark',
};
$docIconColor = fn(?string $mime): string => match (true) {
    str_contains($mime ?? '', 'pdf') => '#dc3545',
    str_contains($mime ?? '', 'image') => '#0dcaf0',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => '#0d6efd',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'var(--primary-color)',
    default => '#aaa',
};
?>

<?php
// CSRF token disponible para el JS dinámico de payment_section (que arma forms ad-hoc).
$csrfToken = $this->request->getAttribute('csrfToken') ?? '';
?>
<input type="hidden" name="_csrfToken" value="<?= h($csrfToken) ?>">

<!-- Encabezado de página -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Legalización del Anticipo</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Layout: formulario izquierda + soportes derecha -->
<div class="sgi-invoice-layout">

<!-- ── Columna izquierda ── -->
<div class="sgi-invoice-form">
<div class="card card-primary mb-4">

    <!-- Cabecera: identificador + estado -->
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi bi-clipboard-check"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;font-family:monospace;letter-spacing:-.01em;">
                    Legalización Anticipo #<?= h($invoice->id) ?>
                </div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                    Beneficiario: <strong style="color:#777;"><?= h($beneficiary) ?></strong>
                </div>
            </div>
        </div>
        <span class="badge <?= $ps[1] ?>"><?= $ps[0] ?></span>
    </div>

    <!-- Pipeline progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('pipeline_progress', [
            'currentStatus' => $leg->status,
            'pipelineStatuses' => AdvanceConstants::PIPELINE_STATUSES,
            'pipelineLabels' => $legPipelineLabels,
            'isRejected' => false,
            'statusIcons' => AdvanceConstants::STATUS_ICONS,
        ]) ?>
    </div>

    <!-- Ledger -->
    <div style="padding:1rem 1.5rem .75rem;">
        <div class="sgi-ledger">
            <!-- Fila 1: Beneficiario + Documento + Anticipo -->
            <div class="sgi-ledger-item" style="grid-column:span 2;">
                <div class="sgi-ledger-label">Beneficiario (<?= h($beneficiaryKind) ?>)</div>
                <div class="sgi-ledger-value" title="<?= h($beneficiary) ?>">
                    <?= h($beneficiary) ?>
                </div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Documento</div>
                <div class="sgi-ledger-value"><?= h(trim($beneficiaryDocType . ' ' . ($beneficiaryDoc ?? '—'))) ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Anticipo</div>
                <div class="sgi-ledger-value --amount">
                    $ <?= number_format($advanceTotal, 0, ',', '.') ?>
                </div>
            </div>
            <!-- Fila 2: Vinculadas + Total Vinculado + Diferencia + Caso -->
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Facturas Vinculadas</div>
                <div class="sgi-ledger-value"><?= $linkedCount ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Total Vinculado</div>
                <div class="sgi-ledger-value --amount">
                    $ <?= number_format($linkedTotal, 0, ',', '.') ?>
                </div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Diferencia</div>
                <div class="sgi-ledger-value">
                    <span class="badge <?= $diffBadgeClass ?>">
                        $ <?= number_format($diff, 0, ',', '.') ?>
                    </span>
                </div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Caso</div>
                <div class="sgi-ledger-value">
                    <?php if ($leg->case_type): ?>
                        <?= h($caseLabels[$leg->case_type] ?? $leg->case_type) ?>
                    <?php else: ?>
                        <span class="--muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Fila 3: Fechas -->
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Iniciada</div>
                <div class="sgi-ledger-value"><?= $leg->created?->format('d/m/Y') ?? '—' ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Legalizada</div>
                <div class="sgi-ledger-value"><?= $leg->legalized_at ? h(date('d/m/Y H:i', strtotime((string)$leg->legalized_at))) : '—' ?></div>
            </div>
        </div>
    </div>

    <div class="card-body p-4" style="padding-top:0 !important;">

        <!-- Sección: Facturas vinculadas -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-link-45deg me-1"></i>Facturas vinculadas
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
                <?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
                <button type="button" class="btn btn-sm sgi-btn-primary" data-bs-toggle="modal" data-bs-target="#advanceLinkModal">
                    <i class="bi bi-plus-lg me-1"></i>Vincular
                </button>
                <?php endif; ?>
            </div>
            <?php if ($linkedCount === 0): ?>
            <div class="text-muted text-center py-3" style="font-size:.85rem;">
                <i class="bi bi-inbox me-1"></i>Sin facturas vinculadas
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th># Factura</th>
                            <th>Beneficiario</th>
                            <th>Fecha</th>
                            <th class="text-end">Monto</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($linkedInvoices as $li): ?>
                        <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $li->id]) ?>">
                            <td style="font-family:monospace;font-weight:600;"><?= h($li->invoice_number ?: '#' . $li->id) ?></td>
                            <td><?= h($li->provider->name ?? ($li->employee->full_name ?? '—')) ?></td>
                            <td><?= $li->issue_date?->format('d/m/Y') ?? '—' ?></td>
                            <td class="text-end" style="font-weight:600;">$ <?= $this->Number->format((float)$li->amount, ['places' => 2]) ?></td>
                            <td><span class="badge bg-secondary"><?= h($li->pipeline_status) ?></span></td>
                            <td class="text-end">
                                <?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-x-lg"></i>',
                                    ['action' => 'unlinkInvoice', $invoice->id, $li->id],
                                    ['class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'confirm' => '¿Desvincular esta factura?', 'title' => 'Desvincular']
                                ) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#fafafa;">
                            <td colspan="3" class="text-end" style="font-weight:600;color:#666;">Total vinculado</td>
                            <td class="text-end" style="font-weight:700;color:var(--primary-color);">
                                $ <?= $this->Number->format($linkedTotal, ['places' => 2]) ?>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sección: Acciones del estado -->
        <?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
        <div class="sgi-sticky-actions">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?= $this->Form->postLink(
                    '<i class="bi bi-arrow-right-circle me-1"></i>Pasar a Revisión y Firmas',
                    ['action' => 'moveToRevision', $leg->advance_invoice_id],
                    ['class' => 'btn sgi-btn-primary', 'escape' => false, 'confirm' => '¿Pasar a Revisión y Firmas?']
                ) ?>
                <small class="text-muted">Requiere ≥1 factura vinculada y la relación de facturas adjunta.</small>
            </div>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_REVISION_FIRMAS): ?>
        <div class="sgi-sticky-actions">
            <div class="d-flex flex-wrap gap-2">
                <?= $this->Form->postLink(
                    '<i class="bi bi-check-circle me-1"></i>Marcar como firmado',
                    ['action' => 'markSigned', $leg->advance_invoice_id],
                    ['class' => 'btn btn-primary', 'escape' => false]
                ) ?>
                <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#advReturnModal">
                    <i class="bi bi-arrow-return-left me-1"></i>Devolver a Validación
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
        <div class="sgi-sticky-actions">
            <?php if (abs($diff) < 0.005): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-check-circle me-1"></i>Marcar legalizada (caso exacto)',
                ['action' => 'markExact', $leg->advance_invoice_id],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
            <?php elseif ($diff > 0.005): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'registerShortage', $leg->advance_invoice_id]]) ?>
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Monto del faltante (consignación pendiente)</label>
                    <input type="text" name="shortage_amount" class="form-control currency-input"
                           value="<?= number_format($diff, 0, ',', '.') ?>" required>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="bi bi-arrow-down-circle me-1"></i>Registrar faltante
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
            <?php else: ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'registerSurplus', $leg->advance_invoice_id]]) ?>
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Monto del sobrante (reintegro a beneficiario)</label>
                    <input type="text" name="surplus_amount" class="form-control currency-input"
                           value="<?= number_format(abs($diff), 0, ',', '.') ?>" required>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-danger w-100">
                        <i class="bi bi-arrow-up-circle me-1"></i>Registrar sobrante
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>
            <?php endif; ?>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_TESORERIA && $leg->case_type === AdvanceConstants::CASE_FALTANTE): ?>
        <div class="sgi-sticky-actions">
            <div class="d-flex flex-column gap-1 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="text-uppercase fw-semibold flex-shrink-0"
                          style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                        <i class="bi bi-bank me-1"></i>Confirmar consignación
                    </span>
                    <div style="flex:1;height:1px;background:var(--border-color);"></div>
                </div>
                <div class="d-flex align-items-center gap-2"
                     style="border-left:2px solid var(--secondary-color);padding:.35rem .7rem;">
                    <i class="bi bi-info-circle-fill flex-shrink-0"
                       style="color:var(--secondary-color);font-size:.85rem;"></i>
                    <span style="font-size:.75rem;color:#666;">Monto pendiente:</span>
                    <strong style="color:#222;font-size:.85rem;letter-spacing:-.01em;">
                        $ <?= $this->Number->format((float)$leg->shortage_amount, ['places' => 2]) ?>
                    </strong>
                </div>
            </div>

            <?= $this->Form->create(null, ['url' => ['action' => 'confirmShortage', $leg->advance_invoice_id], 'type' => 'file']) ?>
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
                <button type="submit" class="btn sgi-btn-primary">
                    <i class="bi bi-check-circle me-1"></i>Confirmar consignación
                </button>
            </div>
            <?= $this->Form->end() ?>
        </div>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_TESORERIA && $leg->case_type === AdvanceConstants::CASE_SOBRANTE): ?>
        <?php
        $isTesoreria = ($roleName ?? '') === \App\Constants\RoleConstants::TESORERIA
            || ($roleName ?? '') === \App\Constants\RoleConstants::ADMIN;
        ?>
        <?= $this->element('payment_section', [
            'payments' => [],
            'bankingEntities' => $bankingEntities,
            'addPaymentUrl' => ['controller' => 'Advances', 'action' => 'registerRefund', $leg->advance_invoice_id],
            'paymentStatus' => null,
            'totalAmount' => (float)$leg->surplus_amount,
            'mode' => 'tesoreria_register',
            'canRegisterPayment' => $isTesoreria,
            'canAuthorize' => false,
            'canDelete' => false,
            'forceFullAmount' => true,
            'singlePaymentOnly' => true,
            'sectionTitle' => 'Reintegro al beneficiario',
            'sectionIcon' => 'bi-arrow-up-circle',
        ]) ?>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_AUTORIZACION_PAGO && $leg->case_type === AdvanceConstants::CASE_SOBRANTE): ?>
        <?php
        $isContador = ($roleName ?? '') === \App\Constants\RoleConstants::CONTADOR
            || ($roleName ?? '') === \App\Constants\RoleConstants::ADMIN;
        ?>
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
            'canAuthorize' => $isContador,
            'canDelete' => false,
            'rejectMessage' => '¿Rechazar el reintegro? La legalización volverá a Tesorería para nuevo registro.',
            'sectionTitle' => 'Autorización de Reintegro',
            'sectionIcon' => 'bi-shield-check',
        ]) ?>
        <?php elseif ($leg->status === AdvanceConstants::STATUS_LEGALIZADA): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 mb-0">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <span>
                <strong>Legalizada</strong>
                <?php if ($leg->legalized_at): ?> el <?= h(date('d/m/Y H:i', strtotime((string)$leg->legalized_at))) ?><?php endif; ?>
                <?php if ($leg->case_type): ?> — caso <strong><?= h($caseLabels[$leg->case_type] ?? $leg->case_type) ?></strong><?php endif; ?>.
            </span>
        </div>
        <?php endif; ?>

    </div>
</div>
</div><!-- /columna izquierda -->

<!-- ── Columna derecha: soportes + observaciones ── -->
<div class="sgi-invoice-sidebar">

<!-- Soportes -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
        </span>
    </div>

    <!-- Documento Especial: Relación de facturas -->
    <div style="padding:.3rem .875rem;background:rgba(70,157,97,.06);border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.4rem;">
        <span class="badge" style="font-size:.6rem;background:var(--primary-color);color:#fff;">Relación de facturas</span>
    </div>
    <?php if ($relationDocument): ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);background:rgba(70,157,97,.03);">
        <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
            <i class="bi <?= $docIcon($relationDocument->mime_type ?? null) ?>"
               style="color:<?= $docIconColor($relationDocument->mime_type ?? null) ?>;font-size:1rem;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"
                 title="<?= h($relationDocument->file_name ?? '') ?>">
                <?= h($relationDocument->file_name ?? 'Documento') ?>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
                <?php if ($relationDocument->signature_status === AdvanceConstants::SIGNATURE_SIGNED): ?>
                <span class="badge bg-success" style="font-size:.6rem;">
                    <i class="bi bi-check-circle me-1"></i>Firmado
                    <?php if ($relationDocument->signed_by_user): ?>
                        — <?= h($relationDocument->signed_by_user->full_name ?? '') ?>
                    <?php endif; ?>
                </span>
                <?php else: ?>
                <span class="badge bg-warning text-dark" style="font-size:.6rem;">
                    <i class="bi bi-clock me-1"></i>Pendiente de firma
                </span>
                <?php endif; ?>
                <?php if ($relationDocument->created): ?>
                <span style="font-size:.65rem;color:#bbb;">
                    <i class="bi bi-clock" style="font-size:.6rem;"></i>
                    <?= $relationDocument->created->format('d/m/Y H:i') ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:.25rem;flex-shrink:0;">
            <?php if (in_array($leg->status, [AdvanceConstants::STATUS_VALIDACION, AdvanceConstants::STATUS_REVISION_FIRMAS], true)): ?>
            <?= $this->Form->create(null, [
                'url' => ['action' => 'uploadRelationDocument', $leg->advance_invoice_id],
                'type' => 'file',
                'id' => 'rel-doc-update-form',
                'class' => 'd-inline',
            ]) ?>
            <input type="file" name="relation_document" id="rel-doc-file-update" required
                   accept=".pdf,.jpg,.jpeg,.png"
                   style="display:none;" onchange="document.getElementById('rel-doc-update-form').submit();">
            <label for="rel-doc-file-update" class="btn btn-sm btn-outline-primary"
                   style="width:28px;height:28px;padding:0;font-size:.75rem;line-height:28px;text-align:center;cursor:pointer;" title="Reemplazar">
                <i class="bi bi-arrow-repeat"></i>
            </label>
            <?= $this->Form->end() ?>
            <?php endif; ?>
            <?php if (!empty($relationDocument->file_path)): ?>
            <?= $this->Html->link(
                '<i class="bi bi-box-arrow-up-right"></i>',
                '/' . $relationDocument->file_path,
                ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'width:28px;height:28px;padding:0;font-size:.75rem;line-height:28px;text-align:center;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
            ) ?>
            <?php endif; ?>
        </div>
    </div>
    <div style="height:2px;background:var(--primary-color);opacity:.35;"></div>
    <?php elseif ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);background:rgba(70,157,97,.03);">
        <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-file-earmark-x" style="color:#ccc;font-size:1rem;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <span style="font-size:.76rem;color:#999;">Sin documento adjunto</span>
        </div>
        <?= $this->Form->create(null, [
            'url' => ['action' => 'uploadRelationDocument', $leg->advance_invoice_id],
            'type' => 'file',
            'id' => 'rel-doc-upload-form',
            'class' => 'd-inline flex-shrink-0',
        ]) ?>
        <input type="file" name="relation_document" id="rel-doc-file-new" required
               accept=".pdf,.jpg,.jpeg,.png"
               style="display:none;" onchange="document.getElementById('rel-doc-upload-form').submit();">
        <label for="rel-doc-file-new" class="btn btn-sm btn-outline-primary"
               style="padding:.25rem .5rem;font-size:.72rem;line-height:1;cursor:pointer;" title="Subir">
            <i class="bi bi-upload me-1"></i>Subir
        </label>
        <?= $this->Form->end() ?>
    </div>
    <div style="height:2px;background:var(--primary-color);opacity:.35;"></div>
    <?php else: ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);background:rgba(70,157,97,.03);">
        <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-file-earmark-x" style="color:#ccc;font-size:1rem;"></i>
        </div>
        <span style="font-size:.76rem;color:#c8c8c8;">Sin documento</span>
    </div>
    <div style="height:2px;background:var(--primary-color);opacity:.35;"></div>
    <?php endif; ?>

    <!-- Comprobante de consignación (caso faltante) -->
    <?php if ($leg->case_type === AdvanceConstants::CASE_FALTANTE && $leg->shortage_receipt_path): ?>
    <div style="padding:.3rem .875rem;background:#fff8e6;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.4rem;">
        <span class="badge" style="font-size:.6rem;background:#cd6a15;color:#fff;">Comprobante de consignación</span>
    </div>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">
        <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-file-earmark-pdf" style="color:#dc3545;font-size:1rem;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:.79rem;font-weight:600;color:#1a1a1a;">
                <?= h($leg->shortage_receipt_number ?: 'Comprobante') ?>
            </div>
            <div style="font-size:.65rem;color:#bbb;margin-top:.25rem;">
                <?php if ($leg->shortage_received_at): ?>
                <i class="bi bi-clock"></i> <?= h(date('d/m/Y', strtotime((string)$leg->shortage_received_at))) ?>
                <?php endif; ?>
            </div>
        </div>
        <?= $this->Html->link(
            '<i class="bi bi-box-arrow-up-right"></i>',
            '/' . $leg->shortage_receipt_path,
            ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'width:28px;height:28px;padding:0;font-size:.75rem;line-height:28px;text-align:center;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
        ) ?>
    </div>
    <?php endif; ?>

    <!-- Historial de firmas (rechazadas) -->
    <?php if (!empty($signatureHistory)): ?>
    <div style="padding:.3rem .875rem;background:#f8f9fa;border-bottom:1px solid var(--border-color);">
        <span style="font-size:.65rem;color:#888;text-transform:uppercase;letter-spacing:.1em;font-weight:600;">Historial</span>
    </div>
    <?php foreach ($signatureHistory as $sig): ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.65rem .875rem;border-bottom:1px solid var(--border-color);opacity:.7;">
        <div style="width:30px;height:30px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
            <i class="bi <?= $docIcon($sig->mime_type ?? null) ?>" style="color:#999;font-size:.9rem;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:.74rem;font-weight:600;color:#777;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= h($sig->file_name ?? '—') ?>
            </div>
            <div style="font-size:.62rem;color:#aaa;margin-top:.2rem;">
                <span class="badge bg-danger" style="font-size:.55rem;">Rechazado</span>
                <?php if ($sig->rejection_reason): ?>
                — <?= h($sig->rejection_reason) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($sig->file_path)): ?>
        <?= $this->Html->link(
            '<i class="bi bi-box-arrow-up-right"></i>',
            '/' . $sig->file_path,
            ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'width:26px;height:26px;padding:0;font-size:.7rem;line-height:26px;text-align:center;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
        ) ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!$relationDocument && empty($signatureHistory) && !$leg->shortage_receipt_path): ?>
    <div style="padding:1.5rem 1rem;text-align:center;color:#c8c8c8;">
        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
        <span style="font-size:.8rem;">Sin soportes adjuntos</span>
    </div>
    <?php endif; ?>
</div>

<!-- Observaciones -->
<?php $obsCount = count($invoice->invoice_observations ?? []); ?>
<div class="card card-primary sgi-obs-card" style="display:flex;flex-direction:column;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <span id="obs-count" class="sgi-folder-count ms-auto" <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
    </div>

    <div id="obs-chat-scroll" class="sgi-obs-list">
        <?php foreach ($invoice->invoice_observations ?? [] as $obs): ?>
            <?= $this->element('observation_bubble', [
                'observation' => $obs,
                'isMine' => $currentUser && $obs->user_id === $currentUser->id,
            ]) ?>
        <?php endforeach; ?>
    </div>

    <div id="obs-empty-state" class="sgi-obs-empty" <?= $obsCount > 0 ? 'hidden' : '' ?>>
        <i class="bi bi-chat-square-dots" style="font-size:1.75rem;"></i>
        <span style="font-size:.78rem;">Sin observaciones aún</span>
    </div>

    <div class="sgi-obs-input-bar">
        <?= $this->Form->create(null, ['url' => ['controller' => 'Invoices', 'action' => 'addObservation', $invoice->id], 'id' => 'obs-form']) ?>
        <div class="sgi-obs-compose">
            <textarea id="obs-message" name="message" class="auto-resize" rows="1"
                      placeholder="Escriba una observación..."></textarea>
            <button type="submit" class="sgi-obs-compose-send" title="Enviar">
                <i class="bi bi-send"></i>
            </button>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

</div><!-- /columna derecha -->

</div><!-- /layout -->

<?php if ($leg && $leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
<?= $this->element('advance_link_modal', ['leg' => $leg]) ?>
<?php endif; ?>

<?= $this->element('observation_chat_init') ?>

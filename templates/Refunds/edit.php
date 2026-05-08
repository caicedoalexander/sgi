<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\RefundEditViewModel $viewModel
 * @var \App\Model\Entity\User|null $currentUser
 */
use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;

$record = $viewModel->record;
$availableInvoices = $viewModel->availableInvoices;
$operationCenters = $viewModel->operationCenters;
$groupFilters = $viewModel->groupFilters;
$employees = $viewModel->employees;
$providers = $viewModel->providers;
$bankingEntities = $viewModel->bankingEntities;
$previousStatus = $viewModel->previousStatus;
$regressLockMessage = $viewModel->regressLockMessage;
$currentStatus = $viewModel->currentStatus;
$advanceErrors = $viewModel->advanceErrors;
$canRegisterPayment = $viewModel->canRegisterPayment;
$canAuthorizePayment = $viewModel->canAuthorizePayment;
$canConfirmPayment = $viewModel->canConfirmPayment;
$canRegress = $viewModel->canRegress;
$syntheticPayments = $viewModel->syntheticPayments;
$pipelineLabels = $viewModel->pipelineLabels;
$roleName = $viewModel->roleName;
$nextStatus = $viewModel->nextStatus;

$this->assign('title', 'Editar Reintegro ' . $record->code);

$statusBadge = [
    'agrupacion' => 'bg-info text-dark',
    'contabilidad' => 'bg-primary',
    'tesoreria' => 'bg-warning text-dark',
    'autorizacion_pago' => 'bg-secondary',
    'pagada' => 'bg-success',
];
$statusLabels = RefundConstants::STATUS_LABELS;

$nextStatus = RefundConstants::TRANSITIONS[$record->status] ?? null;

$readyForPaymentLabels = [
    InvoiceConstants::READY_FOR_PAYMENT_SI => 'Sí',
    InvoiceConstants::READY_FOR_PAYMENT_PRIORITARIO => 'Pago Prioritario',
];
$readyForPaymentOptions = ['' => '-- Seleccione --'] + array_combine(
    InvoiceConstants::READY_FOR_PAYMENT_OPTIONS,
    array_map(fn($v) => $readyForPaymentLabels[$v] ?? $v, InvoiceConstants::READY_FOR_PAYMENT_OPTIONS)
);
$paymentStatusOptions = ['' => '-- Seleccione --', InvoiceConstants::PAYMENT_FULL => 'Pago total', InvoiceConstants::PAYMENT_PARTIAL => 'Pago Parcial'];

// Determine which sections to show based on status
$statusIndex = array_search($record->status, RefundConstants::STATUSES);
$showAccounting = $statusIndex >= 1; // contabilidad or later
$showTreasury = $statusIndex >= 2;   // tesoreria or later

$invoiceOptions = [];
foreach ($availableInvoices as $inv) {
    $label = ($inv->invoice_number ?? '#' . $inv->id)
        . ' - ' . ($inv->provider->name ?? 'Sin proveedor')
        . ' - ' . ($inv->operation_center->name ?? '')
        . ' - $' . number_format((float)$inv->amount, 0, ',', '.')
        . ' (' . ($inv->issue_date?->format('d/m/Y') ?? '') . ')';
    $invoiceOptions[$inv->id] = $label;
}

// Can edit in current status?
$canEditAccounting = $record->isContabilidad();
$canEditTreasury = $record->isTesoreria();
$canSave = $record->isAgrupacion() || $record->isContabilidad() || $record->isTesoreria();

// Unified submit button (same pattern as invoice edit)
$canAdvance = $nextStatus !== null;
if ($canAdvance && empty($advanceErrors) && $nextStatus) {
    $nextLabel = $statusLabels[$nextStatus] ?? $nextStatus;
    $btnLabel  = '<i class="bi bi-arrow-right-circle me-1"></i>Guardar y Avanzar a: ' . h($nextLabel);
    $btnClass  = 'btn btn-primary';
} else {
    $btnLabel = '<i class="bi bi-save me-1"></i>Guardar Cambios';
    $btnClass = 'btn btn-primary';
}

// Compute invoice count and total for ledger
$invoiceCount = count($record->invoices ?? []);
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Reintegro</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1"></i>Ver',
            ['action' => 'view', $record->id],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Alerta de avance pendiente -->
<?php if ($canAdvance && !empty($advanceErrors)): ?>
<div class="alert alert-warning mb-4">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
        <div>
            <strong>Para avanzar al siguiente estado complete:</strong>
            <ul class="mb-0 mt-1 ps-3">
                <?php foreach ($advanceErrors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Layout dos columnas -->
<div class="sgi-invoice-layout">

<!-- Columna izquierda: formulario -->
<div class="sgi-invoice-form">
<div class="card card-primary mb-4">
    <!-- Header -->
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi bi-wallet2"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;font-family:monospace;letter-spacing:-.01em;">
                    <?= h($record->code) ?>
                </div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                    Total: <strong style="color:var(--primary-color);">$ <?= $this->Number->format($record->total_amount, ['places' => 2]) ?></strong>
                </div>
            </div>
        </div>
        <span class="badge <?= $statusBadge[$record->status] ?? 'bg-dark' ?>">
            <?= $statusLabels[$record->status] ?? $record->status ?>
        </span>
    </div>

    <!-- Progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('refund_progress', ['status' => $record->status]) ?>
    </div>

    <!-- ── Ficha resumen (ledger) ── -->
    <div style="padding:1rem 1.5rem .75rem;">
        <div class="sgi-ledger">
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Código</div>
                <div class="sgi-ledger-value" style="font-family:monospace;"><?= h($record->code ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Centro de Operación</div>
                <div class="sgi-ledger-value"><?= h($record->operation_center->name ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Facturas</div>
                <div class="sgi-ledger-value"><?= $invoiceCount ?> factura<?= $invoiceCount !== 1 ? 's' : '' ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Total</div>
                <div class="sgi-ledger-value --amount">$ <?= number_format((float)$record->total_amount, 0, ',', '.') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Creado</div>
                <div class="sgi-ledger-value"><?= $record->created ? h($record->created->format('d/m/Y')) : '—' ?></div>
            </div>
            <div class="sgi-ledger-item" style="grid-column:span 2;">
                <div class="sgi-ledger-label">Beneficiario</div>
                <div class="sgi-ledger-value" style="font-weight:600;">
                    <?php
                    $bLabel = RefundConstants::BENEFICIARY_TYPES_LABELS[$record->beneficiary_type] ?? null;
                    $bName = $record->getBeneficiaryName();
                    ?>
                    <?php if ($bName): ?>
                        <?= h($bName) ?> <small class="text-muted">(<?= h($bLabel) ?>)</small>
                    <?php else: ?>
                        <span class="text-muted">— Sin beneficiario —</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body p-4" style="padding-top:0 !important;">
        <?= $this->Form->create($record, ['id' => 'refundEditForm']) ?>
        <?= $this->Form->hidden('expected_status', ['value' => $record->status]) ?>

        <div class="sgi-form-sections">

        <?php
        // ── Section reordering: editable sections first ──
        $sections = [];

        // Beneficiary section (editable only in agrupacion, visible always)
        $sections[] = ['key' => 'beneficiary', 'editable' => $record->isAgrupacion()];

        // Invoices section (always visible, editable only in agrupacion)
        $sections[] = ['key' => 'invoices', 'editable' => $record->isAgrupacion()];

        // Accounting section (visible in contabilidad+)
        if ($showAccounting) {
            $sections[] = ['key' => 'accounting', 'editable' => $canEditAccounting];
        }

        // Treasury section (visible in tesoreria+)
        if ($showTreasury) {
            $sections[] = ['key' => 'treasury', 'editable' => $canEditTreasury];
        }

        // Sort: editable first
        usort($sections, fn($a, $b) => $b['editable'] <=> $a['editable']);

        foreach ($sections as $section):
        ?>

        <?php if ($section['key'] === 'beneficiary' && $section['editable']): ?>
        <!-- Beneficiario (solo editable; en read-only ya aparece en el ledger superior) -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-person-badge me-1"></i>Beneficiario
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="mb-3">
                <label class="form-label">Tipo</label>
                <div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="beneficiary_type" id="bt-employee" value="employee"
                               <?= $record->beneficiary_type === 'employee' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="bt-employee">Empleado</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="beneficiary_type" id="bt-provider" value="provider"
                               <?= $record->beneficiary_type === 'provider' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="bt-provider">Proveedor</label>
                    </div>
                </div>
            </div>
            <div class="mb-3 sgi-beneficiary-employee" <?= $record->beneficiary_type === 'employee' ? '' : 'style="display:none;"' ?>>
                <label class="form-label">Empleado</label>
                <select name="beneficiary_employee_id" class="form-select select2-enable">
                    <option value="">Seleccione un empleado</option>
                    <?php foreach ($employees as $eid => $ename): ?>
                    <option value="<?= (int)$eid ?>" <?= (int)($record->beneficiary_employee_id ?? 0) === (int)$eid ? 'selected' : '' ?>><?= h($ename) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3 sgi-beneficiary-provider" <?= $record->beneficiary_type === 'provider' ? '' : 'style="display:none;"' ?>>
                <label class="form-label">Proveedor</label>
                <select name="beneficiary_provider_id" class="form-select select2-enable">
                    <option value="">Seleccione un proveedor</option>
                    <?php foreach ($providers as $pid => $pname): ?>
                    <option value="<?= (int)$pid ?>" <?= (int)($record->beneficiary_provider_id ?? 0) === (int)$pid ? 'selected' : '' ?>><?= h($pname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($section['key'] === 'invoices'): ?>
        <!-- Facturas agrupadas -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-receipt me-1"></i>Facturas Agrupadas
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
                <span class="sgi-folder-count"><?= $invoiceCount ?></span>
            </div>

            <?php if (!empty($record->invoices)): ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th># Factura</th>
                            <th>Proveedor</th>
                            <th class="text-end">Monto</th>
                            <?php if ($record->isAgrupacion()): ?>
                            <th class="text-center" style="width:60px;"></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($record->invoices as $inv): ?>
                        <tr>
                            <td>
                                <?= $this->Html->link(
                                    $inv->invoice_number ?? '#' . $inv->id,
                                    ['controller' => 'Invoices', 'action' => 'view', $inv->id],
                                    ['style' => 'font-family:monospace;font-weight:600;']
                                ) ?>
                            </td>
                            <td><?= $inv->hasValue('provider') ? h($inv->provider->name) : '—' ?></td>
                            <td class="text-end">$ <?= $this->Number->format($inv->amount, ['places' => 2]) ?></td>
                            <?php if ($record->isAgrupacion()): ?>
                            <td class="text-center">
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-x-lg"></i>',
                                    ['action' => 'removeInvoice', $record->id, $inv->id],
                                    ['confirm' => '¿Remover esta factura del registro?', 'class' => 'btn btn-sm btn-outline-danger', 'style' => 'padding:.15rem .4rem;font-size:.7rem;line-height:1;', 'escape' => false, 'title' => 'Quitar']
                                ) ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="text-end fw-bold">Total:</td>
                            <td class="text-end fw-bold" style="color:var(--primary-color);">
                                $ <?= $this->Number->format($record->total_amount, ['places' => 2]) ?>
                            </td>
                            <?php if ($record->isAgrupacion()): ?>
                            <td></td>
                            <?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php else: ?>
            <div class="alert alert-warning mb-3">
                <i class="bi bi-exclamation-triangle me-1"></i>
                No hay facturas agrupadas. Agregue al menos una factura para poder avanzar.
            </div>
            <?php endif; ?>

            <!-- Agregar más facturas (solo agrupación) — modal compartido -->
            <?php if ($record->isAgrupacion()): ?>
            <div class="mt-2">
                <button type="button" class="btn btn-sm sgi-btn-primary"
                        data-bs-toggle="modal" data-bs-target="#linkRefundInvoicesModal">
                    <i class="bi bi-link-45deg me-1"></i>Vincular facturas
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($section['key'] === 'accounting'): ?>
        <!-- ── Sección: Contabilidad ── -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-calculator me-1"></i>Contabilidad
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label d-block">Causada</label>
                    <div class="form-check">
                        <?php if ($canEditAccounting): ?>
                        <input type="hidden" name="accrued" value="0">
                        <input type="checkbox" name="accrued" value="1" class="form-check-input"
                               id="accrued" <?= !empty($record->accrued) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="accrued">Marcar como causada</label>
                        <?php else: ?>
                        <input type="checkbox" class="form-check-input" disabled <?= !empty($record->accrued) ? 'checked' : '' ?>>
                        <label class="form-check-label">Marcar como causada</label>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Fecha de Causación<?= $canEditAccounting ? ' <span class="text-danger">*</span>' : '' ?></label>
                    <?php if ($canEditAccounting): ?>
                    <input type="text" name="accrual_date" class="form-control flatpickr-date"
                           value="<?= $record->accrual_date ? (is_string($record->accrual_date) ? $record->accrual_date : $record->accrual_date->format('Y-m-d')) : '' ?>">
                    <?php else: ?>
                    <input type="text" class="form-control" disabled
                           value="<?= $record->accrual_date ? (is_string($record->accrual_date) ? $record->accrual_date : $record->accrual_date->format('d/m/Y')) : '' ?>">
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Lista para Pago</label>
                    <?php if ($canEditAccounting): ?>
                    <select name="ready_for_payment" class="form-select">
                        <?php foreach ($readyForPaymentOptions as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= ($record->ready_for_payment ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="text" class="form-control" disabled value="<?= h($record->ready_for_payment ?? '') ?>">
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($section['key'] === 'treasury'): ?>
        <?php if (!empty($record->payment_rejection_reason)
            && $record->status === RefundConstants::STATUS_TESORERIA): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2 mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div>
                <strong>Pago rechazado.</strong>
                <div><?= h($record->payment_rejection_reason) ?></div>
            </div>
        </div>
        <?php endif; ?>
        <?= $this->element('payment_section', [
            'payments'           => $syntheticPayments ?? [],
            'bankingEntities'    => $bankingEntities,
            'addPaymentUrl'      => ['action' => 'registerPayment', $record->id],
            'authorizeUrlFn'     => fn($_) => ['action' => 'authorizePayment', $record->id],
            'rejectUrlFn'        => fn($_) => ['action' => 'rejectPayment', $record->id],
            'canRegisterPayment' => ($record->status === RefundConstants::STATUS_TESORERIA)
                && ($canRegisterPayment ?? false),
            'canAuthorize'       => ($record->status === RefundConstants::STATUS_AUTORIZACION_PAGO)
                && ($canAuthorizePayment ?? false),
            'canDelete'          => false,
            'paymentStatus'      => null,
            'totalAmount'        => (float)$record->total_amount,
            'rejectMessage'      => '¿Rechazar este pago? El registro volverá a Tesorería.',
            'sectionTitle'       => 'Pago',
            'sectionIcon'        => 'bi-bank',
            'forceFullAmount'    => true,
            'singlePaymentOnly'  => true,
        ]) ?>
        <?php endif; ?>

        <?php endforeach; ?>

        </div><!-- /sgi-form-sections -->

        <!-- Botones de acción (sticky) -->
        <?php if ($canSave): ?>
        <div class="sgi-sticky-actions">
            <button type="submit" class="<?= $btnClass ?>">
                <?= $btnLabel ?>
            </button>

            <?php if (!empty($canRegress)):
                $prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
                $isLocked = !empty($regressLockMessage);
            ?>
                <?php if ($isLocked): ?>
                    <button type="button" class="btn btn-outline-secondary"
                            disabled title="<?= h($regressLockMessage) ?>">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Regresar al paso anterior
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Regresar a: <?= h($prevLabel) ?>
                    </button>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($record->isAgrupacion() && !empty($userPermissions['refunds']['can_delete'])): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-trash me-1"></i>Eliminar',
                ['action' => 'delete', $record->id],
                ['class' => 'btn btn-outline-danger', 'escape' => false, 'confirm' => '¿Eliminar este registro? Las facturas agrupadas quedarán libres.']
            ) ?>
            <?php endif; ?>

            <?= $this->Html->link('Cancelar', ['action' => 'view', $record->id], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?php else: ?>
        <div class="sgi-sticky-actions">
            <?= $this->Html->link('Volver', ['action' => 'view', $record->id], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?php endif; ?>

        <?= $this->Form->end() ?>

        <?= $this->element('confirm_payment_card', [
            'isVerificacionPago' => $record->status === RefundConstants::STATUS_VERIFICACION_PAGO,
            'canConfirm' => $canConfirmPayment,
            'confirmUrl' => ['action' => 'confirmPayment', $record->id],
        ]) ?>

        <?php if ($record->isAgrupacion()): ?>
        <?= $this->element('link_invoices_modal', [
            'modalId'    => 'linkRefundInvoicesModal',
            'formUrl'    => ['action' => 'linkInvoices', $record->id],
            'candidates' => $availableInvoices,
            'title'      => 'Vincular facturas — Reintegro',
            'helpText'   => 'Filtre por fecha o centro de operación para acotar la lista.',
            'filterUrl'  => ['action' => 'edit', $record->id],
            'filters'    => $groupFilters,
            'operationCenters' => $operationCenters,
        ]) ?>
        <?php endif; ?>
    </div>
</div>
</div><!-- /columna izquierda -->

<!-- Columna derecha: soportes + observaciones -->
<div class="sgi-invoice-sidebar">

<?php $docs = $record->refund_documents ?? []; ?>
<div class="card card-primary mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= count($docs) ?> doc<?= count($docs) !== 1 ? 's' : '' ?></span>
        </span>
        <?php if (!$record->isPagada()): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadRefundDocModal">
            <i class="bi bi-upload me-1"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <div id="docs-empty-state" style="padding:2rem 1rem;text-align:center;color:#c8c8c8;<?= !empty($docs) ? 'display:none;' : '' ?>">
        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
        <span style="font-size:.8rem;">Sin soportes adjuntos</span>
    </div>
    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
        <?php foreach ($docs as $doc): ?>
            <?= $this->element('document_row', [
                'doc'       => $doc,
                'canDelete' => !$record->isPagada(),
                'deleteUrl' => $this->Url->build(['action' => 'deleteDocument', $record->id, $doc->id]),
                'showBadge' => false,
            ]) ?>
        <?php endforeach; ?>
    </div>
</div>

<!-- Observaciones: chat -->
<?php $obsCount = count($record->refund_observations ?? []); ?>
<div class="card card-primary sgi-obs-card" style="display:flex;flex-direction:column;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <span id="obs-count" class="sgi-folder-count ms-auto" <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
    </div>

    <div id="obs-chat-scroll" class="sgi-obs-list">
        <?php foreach ($record->refund_observations ?? [] as $obs): ?>
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
        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $record->id], 'id' => 'obs-form']) ?>
        <div class="sgi-obs-compose">
            <textarea name="message" class="auto-resize" rows="1"
                      placeholder="Escriba una observación..."></textarea>
            <button type="submit" class="sgi-obs-compose-send" title="Enviar">
                <i class="bi bi-send"></i>
            </button>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

</div><!-- /columna derecha -->

</div><!-- /layout dos columnas -->

<?php if (!$record->isPagada()): ?>
<!-- Modal: Subir Soporte -->
<div class="modal fade" id="uploadRefundDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="upload-doc-form"
                  data-url="<?= $this->Url->build(['action' => 'uploadDocument', $record->id]) ?>"
                  enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Subir Soporte</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tipo de Documento (opcional)</label>
                        <input type="text" name="document_type" class="form-control" placeholder="Ej. Soporte causación, Comprobante...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Archivo</label>
                        <input type="file" name="file" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                        <div class="form-text">Máximo 20 MB — PDF, imágenes, Word o Excel.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Subir</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?= $this->element('document_row_template', ['showBadge' => false]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>

<?= $this->element('observation_chat_init') ?>

<?php $this->append('script') ?>
<script>
(function(){
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.card.card-primary .card-header .sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadRefundDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>
<script>
(function(){
    // Beneficiary radio toggle (only present when in agrupacion)
    var bRadios = document.querySelectorAll('input[name="beneficiary_type"]');
    if (bRadios.length > 0) {
        var empBlock = document.querySelector('.sgi-beneficiary-employee');
        var provBlock = document.querySelector('.sgi-beneficiary-provider');
        function syncBeneficiary() {
            var checked = document.querySelector('input[name="beneficiary_type"]:checked');
            var val = checked ? checked.value : null;
            if (empBlock) empBlock.style.display = val === 'employee' ? '' : 'none';
            if (provBlock) provBlock.style.display = val === 'provider' ? '' : 'none';
        }
        bRadios.forEach(function (r) { r.addEventListener('change', syncBeneficiary); });
    }
})();
</script>

<?php if (!empty($canRegress) && empty($regressLockMessage)):
    $prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
    $currLabel = $pipelineLabels[$currentStatus] ?? $currentStatus;
?>
<!-- Modal: Regresar al paso anterior -->
<div class="modal fade" id="regressStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post"
              action="<?= $this->Url->build(['action' => 'regressStatus', $record->id]) ?>"
              id="regressStatusForm">
            <?= $this->Form->hidden('_csrfToken', ['value' => $this->request->getAttribute('csrfToken')]) ?>
            <?= $this->Form->hidden('expected_status', ['value' => $record->status]) ?>
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Regresar al paso anterior
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Este registro volverá del paso
                        <strong><?= h($currLabel) ?></strong>
                        al paso
                        <strong><?= h($prevLabel) ?></strong>.
                        Las facturas vinculadas también se regresarán al estado correspondiente.
                    </p>
                    <div class="mb-2">
                        <label for="regressReason" class="form-label">
                            Motivo de la regresión <span class="text-danger">*</span>
                        </label>
                        <textarea name="reason" id="regressReason"
                                  class="form-control" rows="4"
                                  required minlength="10" maxlength="500"
                                  placeholder="Describa por qué está regresando este registro..."></textarea>
                        <div class="form-text">Mín. 10 caracteres · Máx. 500.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" id="regressConfirmBtn" class="btn btn-warning" disabled>
                        Confirmar regreso
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var ta = document.getElementById('regressReason');
    var btn = document.getElementById('regressConfirmBtn');
    if (!ta || !btn) return;
    ta.addEventListener('input', function () {
        btn.disabled = ta.value.trim().length < 10;
    });
})();
</script>
<?php endif; ?>
<?php $this->end() ?>

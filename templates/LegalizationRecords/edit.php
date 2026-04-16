<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\LegalizationRecord $record
 * @var iterable $availableInvoices
 * @var iterable $operationCenters
 * @var array $groupFilters
 * @var bool $canDeleteDocuments
 */
use App\Constants\InvoiceConstants;
use App\Constants\LegalizationConstants;
$groupFilters = $groupFilters ?? [];

$this->assign('title', 'Editar Legalización ' . ($record->code ?? '#' . $record->id));

$statusBadge = [
    LegalizationConstants::STATUS_AGRUPACION => 'bg-info text-dark',
    LegalizationConstants::STATUS_CONTABILIDAD => 'bg-primary',
    LegalizationConstants::STATUS_TESORERIA => 'bg-warning text-dark',
    LegalizationConstants::STATUS_AUT_PAGO => 'bg-info text-dark',
    LegalizationConstants::STATUS_PAGADO => 'bg-success',
];
$statusLabels = LegalizationConstants::STATUS_LABELS;

$nextStatus = LegalizationConstants::TRANSITIONS[$record->status] ?? null;

$readyForPaymentOptions = [
    ''                   => '-- Seleccione --',
    'Si'                 => 'Sí',
    'No'                 => 'No',
    'Anticipo Empleado'  => 'Anticipo Empleado',
    'Anticipo Proveedor' => 'Anticipo Proveedor',
    'Pago prioritario'   => 'Pago prioritario',
    'Pago PSE'           => 'Pago PSE',
    'No Legalización'    => 'No Legalización',
    'Reintegro'          => 'Reintegro',
];
$paymentStatusOptions = ['' => '-- Seleccione --', InvoiceConstants::PAYMENT_FULL => 'Pago total', InvoiceConstants::PAYMENT_PARTIAL => 'Pago Parcial'];

// Determine which sections to show based on status
$statusIndex = array_search($record->status, LegalizationConstants::STATUSES);
$showAccounting = $statusIndex >= 1; // contabilidad or later
$showTreasury = $statusIndex >= 2;   // tesoreria or later

$docIcon = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => 'bi-file-earmark-pdf',
    str_contains($mime ?? '', 'image') => 'bi-file-earmark-image',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => 'bi-file-earmark-word',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'bi-file-earmark-excel',
    default => 'bi-file-earmark',
};
$docIconColor = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => '#dc3545',
    str_contains($mime ?? '', 'image') => '#0dcaf0',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => '#0d6efd',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'var(--primary-color)',
    default => '#aaa',
};

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
    $btnClass  = 'btn btn-success';
} else {
    $btnLabel = '<i class="bi bi-save me-1"></i>Guardar Cambios';
    $btnClass = 'btn btn-primary';
}

// Compute invoice count and total for ledger
$invoiceCount = count($record->invoices ?? []);
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Legalización</span>
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
                <i class="bi bi-file-earmark-check"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;font-family:monospace;letter-spacing:-.01em;">
                    <?= h($record->code ?? '#' . $record->id) ?>
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
        <?= $this->element('legalization_progress', ['status' => $record->status]) ?>
    </div>

    <!-- ── Ficha resumen (ledger) ── -->
    <div style="padding:1rem 1.5rem .75rem;">
        <div class="sgi-ledger">
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Código</div>
                <?php if (!$record->isPagado()): ?>
                <div class="sgi-ledger-value"><input type="text" name="code" form="legalizationEditForm" class="form-control form-control-sm" style="font-family:monospace;max-width:200px;" maxlength="30" value="<?= h($record->code ?? '') ?>" placeholder="Opcional"></div>
                <?php else: ?>
                <div class="sgi-ledger-value" style="font-family:monospace;"><?= h($record->code ?? '—') ?></div>
                <?php endif; ?>
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
            <?php if ($record->notes && !$record->isAgrupacion() && !$record->isContabilidad()): ?>
            <div class="sgi-ledger-item" style="grid-column:span 4;">
                <div class="sgi-ledger-label">Notas</div>
                <div class="sgi-ledger-value" style="white-space:normal;font-weight:400;font-size:.8rem;color:#555;"><?= nl2br(h($record->notes)) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body p-4" style="padding-top:0 !important;">
        <?= $this->Form->create($record, ['id' => 'legalizationEditForm']) ?>

        <div class="sgi-form-sections">

        <?php
        // ── Section reordering: editable sections first ──
        $sections = [];

        // Notes section (editable in agrupacion & contabilidad)
        if ($record->isAgrupacion() || $record->isContabilidad()) {
            $sections[] = ['key' => 'notes', 'editable' => true];
        }

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

        <?php if ($section['key'] === 'notes'): ?>
        <!-- Notas -->
        <div class="mb-4">
            <label class="form-label">Notas</label>
            <textarea name="notes" class="form-control auto-resize" rows="2"><?= h($record->notes ?? '') ?></textarea>
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

            <!-- Agregar más facturas (solo agrupación) -->
            <?php if ($record->isAgrupacion()): ?>
            <div class="mt-2 p-3" style="background:#f9fafb;border:1px solid var(--border-color);">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-funnel" style="font-size:.8rem;color:#888;"></i>
                    <span style="font-size:.78rem;font-weight:600;color:#555;">Buscar facturas para agrupar</span>
                </div>
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-md-3">
                        <label class="form-label mb-1" style="font-size:.7rem;color:#888;">Desde</label>
                        <input type="text" form="groupFilterForm" name="date_from" class="form-control form-control-sm flatpickr-date"
                               value="<?= h($groupFilters['date_from'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1" style="font-size:.7rem;color:#888;">Hasta</label>
                        <input type="text" form="groupFilterForm" name="date_to" class="form-control form-control-sm flatpickr-date"
                               value="<?= h($groupFilters['date_to'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1" style="font-size:.7rem;color:#888;">Centro Op.</label>
                        <select form="groupFilterForm" name="operation_center_id" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <?php foreach ($operationCenters as $ocId => $ocName): ?>
                            <option value="<?= $ocId ?>" <?= ($groupFilters['operation_center_id'] ?? '') == $ocId ? 'selected' : '' ?>><?= h($ocName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" form="groupFilterForm" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-search me-1"></i>Buscar
                        </button>
                    </div>
                </div>
                <!-- Hidden form for GET filter -->
                <form id="groupFilterForm" method="get" action="<?= $this->Url->build(['action' => 'edit', $record->id]) ?>"></form>

                <?php if (!empty($invoiceOptions)): ?>
                <label class="form-label" style="font-size:.78rem;color:#666;">
                    Seleccionar facturas
                    <span class="sgi-folder-count ms-1"><?= count($invoiceOptions) ?> disponible<?= count($invoiceOptions) !== 1 ? 's' : '' ?></span>
                </label>
                <select name="invoice_ids[]" class="form-select select2-enable" multiple
                        data-placeholder="Seleccione facturas para agregar...">
                    <?php foreach ($invoiceOptions as $id => $label): ?>
                    <option value="<?= $id ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <div class="text-muted" style="font-size:.8rem;">
                    <i class="bi bi-info-circle me-1"></i>No hay facturas disponibles<?= !empty($groupFilters['date_from']) || !empty($groupFilters['date_to']) || !empty($groupFilters['operation_center_id']) ? ' con los filtros seleccionados' : '' ?>.
                </div>
                <?php endif; ?>
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
        <?= $this->element('payment_section', [
            'payments'           => $record->legalization_payments ?? [],
            'bankingEntities'    => $bankingEntities,
            'addPaymentUrl'      => ['controller' => 'LegalizationPayments', 'action' => 'addPayment', $record->id],
            'authorizeUrlFn'     => fn($pId) => ['controller' => 'LegalizationPayments', 'action' => 'authorizePayment', $record->id, $pId],
            'rejectUrlFn'        => fn($pId) => ['controller' => 'LegalizationPayments', 'action' => 'rejectPayment', $record->id, $pId],
            'canRegisterPayment' => $isTesoreriaEdit ?? false,
            'canAuthorize'       => $isContadorAutPago ?? false,
            'canDelete'          => false,
            'paymentStatus'      => $record->payment_status ?? null,
            'totalAmount'        => $record->total_amount ?? null,
            'rejectMessage'      => '¿Rechazar este pago? El registro volverá a Tesorería.',
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

            <?php if ($record->isAgrupacion() && !empty($userPermissions['legalizations']['can_delete'])): ?>
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
    </div>
</div>
</div><!-- /columna izquierda -->

<!-- Columna derecha: soportes + observaciones -->
<div class="sgi-invoice-sidebar">

<?php $docs = $record->legalization_documents ?? []; ?>
<div class="card card-primary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= count($docs) ?> doc<?= count($docs) !== 1 ? 's' : '' ?></span>
        </span>
        <?php if (!$record->isPagado()): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadLegDocModal">
            <i class="bi bi-upload me-1"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($docs)): ?>
    <div style="padding:2rem 1rem;text-align:center;color:#c8c8c8;">
        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
        <span style="font-size:.8rem;">Sin soportes adjuntos</span>
    </div>
    <?php else: ?>
    <div style="max-height:420px;overflow-y:auto;">
        <?php foreach ($docs as $doc): ?>
        <div style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">
            <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
                <i class="bi <?= $docIcon($doc->mime_type) ?>"
                   style="color:<?= $docIconColor($doc->mime_type) ?>;font-size:1rem;"></i>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"
                     title="<?= h($doc->document_type ?: $doc->file_name) ?>">
                    <?= h($doc->document_type ?: $doc->file_name) ?>
                </div>
                <?php if ($doc->document_type): ?>
                <div style="font-size:.7rem;color:#999;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:.1rem;"
                     title="<?= h($doc->file_name) ?>"><?= h($doc->file_name) ?></div>
                <?php endif; ?>
                <div style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;">
                    <span style="font-size:.65rem;color:#bbb;">
                        <i class="bi bi-clock" style="font-size:.6rem;"></i>
                        <?= $doc->created?->format('d/m/Y H:i') ?>
                    </span>
                    <?php if ($doc->file_size): ?>
                    <span style="font-size:.63rem;color:#ccc;"><?= $this->Number->toReadableSize($doc->file_size) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:.25rem;flex-shrink:0;align-self:center;">
                <?= $this->Html->link(
                    '<i class="bi bi-box-arrow-up-right"></i>',
                    '/' . $doc->file_path,
                    ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
                ) ?>
                <?php if ($canDeleteDocuments): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-trash"></i>',
                    ['action' => 'deleteDocument', $record->id, $doc->id],
                    ['confirm' => '¿Eliminar este soporte?', 'class' => 'btn btn-sm btn-outline-danger', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'title' => 'Eliminar']
                ) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Observaciones: chat -->
<?php $obsCount = count($record->legalization_observations ?? []); ?>
<div class="card card-primary" style="display:flex;flex-direction:column;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <?php if ($obsCount > 0): ?>
        <span class="sgi-folder-count ms-auto"><?= $obsCount ?></span>
        <?php endif; ?>
    </div>

    <!-- Mensajes -->
    <div id="obs-chat-scroll" style="min-height:100px;max-height:340px;overflow-y:auto;padding:1rem .875rem;background:#f9fafb;display:flex;flex-direction:column;gap:.875rem;">
        <?php if (empty($record->legalization_observations)): ?>
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1.5rem 0;color:#c5c5c5;gap:.5rem;">
            <i class="bi bi-chat-square-dots" style="font-size:1.75rem;"></i>
            <span style="font-size:.78rem;">Sin observaciones aún</span>
        </div>
        <?php else: ?>
        <?php foreach ($record->legalization_observations as $obs):
            $isMine   = $currentUser && $obs->user_id === $currentUser->id;
            $names    = explode(' ', trim($obs->user->full_name ?? ''));
            $initials = strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[array_key_last($names)] ?? '', 0, 1));
        ?>
        <div style="display:flex;flex-direction:column;align-items:<?= $isMine ? 'flex-end' : 'flex-start' ?>;gap:.2rem;">
            <div style="font-size:.63rem;color:#aaa;font-weight:500;letter-spacing:.01em;
                        <?= $isMine ? 'padding-right:.3rem' : 'padding-left:.3rem' ?>">
                <?= $isMine ? 'Tú' : h($obs->user->full_name ?? '') ?>
            </div>
            <div style="max-width:92%;padding:.55rem .8rem;font-size:.81rem;line-height:1.5;word-break:break-word;
                        background:<?= $isMine ? 'var(--primary-color)' : '#fff' ?>;
                        color:<?= $isMine ? '#fff' : '#2d2d2d' ?>;
                        border:1px solid <?= $isMine ? 'var(--primary-color)' : 'var(--border-color)' ?>;
                        border-radius:<?= $isMine ? '10px 10px 2px 10px' : '10px 10px 10px 2px' ?>;">
                <?= nl2br(h($obs->message)) ?>
            </div>
            <div style="font-size:.61rem;color:#c0c0c0;
                        <?= $isMine ? 'padding-right:.3rem' : 'padding-left:.3rem' ?>">
                <?= $obs->created?->format('d/m/Y H:i') ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Input -->
    <div style="border-top:1px solid var(--border-color);padding:.75rem .875rem;background:#fff;">
        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $record->id]]) ?>
        <div class="d-flex gap-2 align-items-end">
            <textarea name="message" class="form-control auto-resize" rows="1"
                      style="font-size:.82rem;background:#f9fafb;border-color:var(--border-color);"
                      placeholder="Escriba una observación..." required></textarea>
            <button type="submit" class="btn btn-primary flex-shrink-0"
                    style="padding:.5rem .75rem;align-self:flex-end;" title="Enviar">
                <i class="bi bi-send" style="font-size:.85rem;"></i>
            </button>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

</div><!-- /columna derecha -->

</div><!-- /layout dos columnas -->

<?php if (!$record->isPagado()): ?>
<!-- Modal: Subir Soporte -->
<div class="modal fade" id="uploadLegDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'uploadDocument', $record->id], 'type' => 'file']) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Subir Soporte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <?= $this->Form->control('document_type', ['class' => 'form-control', 'label' => ['text' => 'Tipo de Documento (opcional)', 'class' => 'form-label'], 'placeholder' => 'Ej. Soporte causación, Comprobante...']) ?>
                </div>
                <div class="mb-3">
                    <?= $this->Form->control('file', ['type' => 'file', 'class' => 'form-control', 'label' => ['text' => 'Archivo', 'class' => 'form-label'], 'required' => true, 'accept' => '.pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx']) ?>
                    <div class="form-text">Máximo 10 MB — PDF, imágenes, Word o Excel.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Subir</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php $this->append('script') ?>
<script>
(function(){
    // Auto-scroll chat al último mensaje
    var chat = document.getElementById('obs-chat-scroll');
    if (chat) chat.scrollTop = chat.scrollHeight;

    // Auto-resize textareas
    function syncHeight(el) {
        el.style.height = '0px';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }
    document.querySelectorAll('textarea.auto-resize').forEach(function(el) {
        el.style.overflow  = 'hidden';
        el.style.resize    = 'none';
        el.style.minHeight = '0px';
        syncHeight(el);
        el.addEventListener('input', function() { syncHeight(this); });
    });
})();
</script>
<?php $this->end() ?>

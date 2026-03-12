<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var array $editableFields
 * @var bool $canAdvance
 * @var string $roleName
 * @var string $currentStatus
 * @var string[] $pipelineStatuses
 * @var string[] $pipelineLabels
 * @var string[] $visibleSections
 * @var bool $isRejected
 * @var string[] $advanceErrors
 * @var string|null $nextStatus
 */
$this->assign('title', 'Editar Factura ' . ($invoice->invoice_number ?? '#' . $invoice->id));

$documentTypes = [
    'Factura'             => 'Factura',
    'Nota Debito'         => 'Nota Débito',
    'Caja menor'          => 'Caja menor',
    'Tarjeta de Crédito'  => 'Tarjeta de Crédito',
    'Reintegro'           => 'Reintegro',
    'Legalización'        => 'Legalización',
    'Recibo'              => 'Recibo',
    'Anticipo'            => 'Anticipo',
];
$approvalOptions       = ['Pendiente' => 'Pendiente', 'Aprobada' => 'Aprobada', 'Rechazada' => 'Rechazada'];
$dianOptions           = ['Pendiente' => 'Pendiente', 'Aprobada' => 'Aprobada', 'Rechazado' => 'Rechazado'];
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
$paymentStatusOptions = ['' => '-- Seleccione --', 'Pago total' => 'Pago total', 'Pago Parcial' => 'Pago Parcial'];

$canEdit = fn(string $field): bool => in_array($field, $editableFields, true);

// Botón de submit
if ($isRejected) {
    $btnLabel = '<i class="bi bi-save me-1"></i>Guardar Cambios';
    $btnClass = 'btn btn-primary';
} elseif ($canAdvance && empty($advanceErrors) && $nextStatus) {
    $nextLabel = $pipelineLabels[$nextStatus] ?? $nextStatus;
    $btnLabel  = '<i class="bi bi-arrow-right-circle me-1"></i>Guardar y Avanzar a: ' . h($nextLabel);
    $btnClass  = 'btn btn-success';
} else {
    $btnLabel = '<i class="bi bi-save me-1"></i>Guardar Cambios';
    $btnClass = 'btn btn-primary';
}

$pipelineBadgeMap = [
    'aprobacion'    => ['Aprobación',    'bg-info text-dark'],
    'contabilidad'  => ['Contabilidad',  'bg-primary'],
    'tesoreria'     => ['Tesorería',     'bg-warning text-dark'],
    'pagada'        => ['Pagada',        'bg-success'],
];
$ps = $pipelineBadgeMap[$currentStatus] ?? ['Desconocido', 'bg-dark'];
?>

<!-- Encabezado de página -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Factura</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1"></i>Ver',
            ['action' => 'view', $invoice->id],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<?php if ($invoice->isInPettyCash()): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-wallet2 fs-5"></i>
    <div>
        Esta factura pertenece al registro de Caja Menor
        <strong><?= $this->Html->link(
            h($invoice->petty_cash_record->code ?? '#' . $invoice->petty_cash_record_id),
            ['controller' => 'PettyCashRecords', 'action' => 'edit', $invoice->petty_cash_record_id],
            ['class' => 'alert-link']
        ) ?></strong>.
        Los cambios de estado se gestionan desde allí.
    </div>
</div>
<?php endif; ?>

<!-- Alerta de avance pendiente -->
<?php if ($canAdvance && !$isRejected && !empty($advanceErrors)): ?>
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

<?php
// Soportes — calcular antes del layout de dos columnas
$uploadableStatuses = ['aprobacion', 'contabilidad', 'tesoreria'];
$showUploadSection  = in_array($currentStatus, $uploadableStatuses, true);
$documentsByStatus  = [];
if (!empty($invoice->invoice_documents)) {
    foreach ($invoice->invoice_documents as $doc) {
        $documentsByStatus[$doc->pipeline_status][] = $doc;
    }
}
$statusLabels = ['aprobacion' => 'Aprobación', 'contabilidad' => 'Contabilidad', 'tesoreria' => 'Tesorería', 'pagada' => 'Pagada'];
$badgeColors  = ['aprobacion' => 'bg-info text-dark', 'contabilidad' => 'bg-primary', 'tesoreria' => 'bg-warning text-dark', 'pagada' => 'bg-success'];
$docIcon = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf')                                                                  => 'bi-file-earmark-pdf',
    str_contains($mime ?? '', 'image')                                                                => 'bi-file-earmark-image',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword')              => 'bi-file-earmark-word',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel')                   => 'bi-file-earmark-excel',
    default                                                                                           => 'bi-file-earmark',
};
$docIconColor = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf')                                                                  => '#dc3545',
    str_contains($mime ?? '', 'image')                                                                => '#0dcaf0',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword')              => '#0d6efd',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel')                   => 'var(--primary-color)',
    default                                                                                           => '#aaa',
};
$totalDocs = array_sum(array_map('count', $documentsByStatus));
$hasSoportes = $showUploadSection || !empty($documentsByStatus);
?>

<!-- Layout: formulario izquierda + soportes derecha -->
<div style="display:flex;gap:1.5rem;align-items:flex-start;">

<!-- ── Columna izquierda: formulario ── -->
<div style="flex:1;min-width:0;">
<div class="card card-primary mb-4">

    <!-- Cabecera: identificador + rol + estado -->
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi bi-receipt"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;font-family:monospace;letter-spacing:-.01em;">
                    <?= h($invoice->invoice_number ?? ('# ' . $invoice->id)) ?>
                </div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                    Rol: <strong style="color:#777;"><?= h($roleName) ?></strong>
                </div>
            </div>
        </div>
        <span class="badge <?= $ps[1] ?>"><?= $ps[0] ?></span>
    </div>

    <!-- Pipeline progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('pipeline_progress', [
            'currentStatus'    => $currentStatus,
            'pipelineStatuses' => $pipelineStatuses,
            'pipelineLabels'   => $pipelineLabels,
            'isRejected'       => $isRejected,
            'paymentStatus'    => $invoice->payment_status,
        ]) ?>
    </div>

    <div class="card-body p-4">
        <?= $this->Form->create($invoice) ?>

        <!-- ── Sección: Información del Documento ── -->
        <?php if (in_array('general', $visibleSections)): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-file-text me-1"></i>Documento
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">No. Factura</label>
                    <?= $this->Form->control('invoice_number', array_merge(
                        ['label' => false, 'placeholder' => 'Ej: FV-001234'],
                        $canEdit('invoice_number')
                            ? ['class' => 'form-control']
                            : ['class' => 'form-control', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo de Documento</label>
                    <?= $this->Form->control('document_type', array_merge(
                        ['label' => false, 'options' => $documentTypes],
                        $canEdit('document_type')
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Orden de Compra</label>
                    <?= $this->Form->control('purchase_order', array_merge(
                        ['label' => false],
                        $canEdit('purchase_order')
                            ? ['class' => 'form-control']
                            : ['class' => 'form-control', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Proveedor</label>
                    <?= $this->Form->control('provider_id', array_merge(
                        ['label' => false, 'options' => $providers, 'empty' => '-- Seleccione --'],
                        $canEdit('provider_id')
                            ? ['class' => 'form-select select2-enable']
                            : ['class' => 'form-select select2-enable', 'disabled' => true]
                    )) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Sección: Fechas ── -->
        <?php if (in_array('dates', $visibleSections)): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-calendar3 me-1"></i>Fechas
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Fecha de Registro</label>
                    <input type="text" class="form-control" disabled
                           value="<?= h($invoice->registration_date?->format('d/m/Y') ?? '') ?>">
                    <input type="hidden" name="registration_date"
                           value="<?= h($invoice->registration_date?->format('Y-m-d') ?? '') ?>">
                </div>
                <?php foreach ([
                    'issue_date' => 'Fecha de Emisión',
                    'due_date'   => 'Fecha de Vencimiento',
                ] as $field => $label): ?>
                <div class="col-md-4">
                    <label class="form-label"><?= $label ?></label>
                    <?php if ($canEdit($field)): ?>
                        <input type="text" name="<?= $field ?>" class="form-control flatpickr-date"
                               value="<?= h($invoice->$field?->format('Y-m-d') ?? '') ?>">
                    <?php else: ?>
                        <input type="text" class="form-control" disabled
                               value="<?= h($invoice->$field?->format('d/m/Y') ?? '') ?>">
                        <input type="hidden" name="<?= $field ?>"
                               value="<?= h($invoice->$field?->format('Y-m-d') ?? '') ?>">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Sección: Clasificación y Valor ── -->
        <?php if (in_array('classification', $visibleSections)): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-tags me-1"></i>Clasificación y Valor
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Centro de Operación</label>
                    <?= $this->Form->control('operation_center_id', array_merge(
                        ['label' => false, 'options' => $operationCenters, 'empty' => '-- Seleccione --'],
                        $canEdit('operation_center_id')
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo de Gasto</label>
                    <?= $this->Form->control('expense_type_id', array_merge(
                        ['label' => false, 'options' => $expenseTypes, 'empty' => '-- Seleccione --'],
                        $canEdit('expense_type_id')
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Centro de Costos</label>
                    <?= $this->Form->control('cost_center_id', array_merge(
                        ['label' => false, 'options' => $costCenters, 'empty' => '-- Seleccione --'],
                        $canEdit('cost_center_id')
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Valor (COP)</label>
                    <?php if ($canEdit('amount')): ?>
                        <input type="text" name="amount" class="form-control currency-input"
                               value="<?= h($invoice->amount ?? '') ?>">
                    <?php else: ?>
                        <input type="text" class="form-control" disabled
                               value="$ <?= number_format((float)($invoice->amount ?? 0), 0, ',', '.') ?>">
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-3">
                <label class="form-label">Detalle</label>
                <?= $this->Form->control('detail', array_merge(
                    ['label' => false, 'type' => 'textarea', 'rows' => 1],
                    $canEdit('detail')
                        ? ['class' => 'form-control auto-resize']
                        : ['class' => 'form-control auto-resize', 'disabled' => true]
                )) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Sección: Revisión ── -->
        <?php if (in_array('revision', $visibleSections)): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-search me-1"></i>Revisión
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Aprobador</label>
                    <?= $this->Form->control('approver_id', array_merge(
                        ['label' => false, 'options' => $approvers, 'empty' => '-- Seleccione --'],
                        $canEdit('approver_id')
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Aprobación Área</label>
                    <?= $this->Form->control('area_approval', [
                        'label' => false,
                        'options' => $approvalOptions,
                        'class' => 'form-select',
                        'disabled' => true,
                    ]) ?>
                    <small class="text-muted">Se actualiza desde el enlace de aprobación</small>
                </div>
                <?php if ($invoice->area_approval_date): ?>
                <div class="col-md-4">
                    <label class="form-label">Fecha Aprobación</label>
                    <input type="text" class="form-control" disabled
                           value="<?= h($invoice->area_approval_date?->format('d/m/Y') ?? '') ?>">
                </div>
                <?php endif; ?>
                <div class="col-md-4">
                    <label class="form-label">Validación DIAN</label>
                    <?= $this->Form->control('dian_validation', array_merge(
                        ['label' => false, 'options' => $dianOptions],
                        $canEdit('dian_validation')
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
                    )) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Sección: Contabilidad ── -->
        <?php if (in_array('accounting', $visibleSections)): ?>
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
                        <?= $this->Form->checkbox('accrued', array_merge(
                            ['class' => 'form-check-input'],
                            $canEdit('accrued') ? [] : ['disabled' => true]
                        )) ?>
                        <?= $this->Form->label('accrued', 'Marcar como causada', ['class' => 'form-check-label']) ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Fecha de Causación</label>
                    <input type="text" class="form-control" disabled
                           value="<?= h($invoice->accrual_date?->format('d/m/Y') ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Lista para Pago</label>
                    <?= $this->Form->control('ready_for_payment', array_merge(
                        ['label' => false, 'options' => $readyForPaymentOptions],
                        $canEdit('ready_for_payment')
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
                    )) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Sección: Tesorería ── -->
        <?php if (in_array('treasury', $visibleSections)): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-bank me-1"></i>Tesorería
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Estado de Pago</label>
                    <?= $this->Form->control('payment_status', array_merge(
                        ['label' => false, 'options' => $paymentStatusOptions],
                        $canEdit('payment_status')
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Fecha de Pago</label>
                    <?php if ($canEdit('payment_date')): ?>
                        <input type="text" name="payment_date" class="form-control flatpickr-date"
                               value="<?= h($invoice->payment_date?->format('Y-m-d') ?? '') ?>">
                    <?php else: ?>
                        <input type="text" class="form-control" disabled
                               value="<?= h($invoice->payment_date?->format('d/m/Y') ?? '') ?>">
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Botones de acción -->
        <?php if (!empty($editableFields)): ?>
        <div class="d-flex gap-2 pt-2" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="<?= $btnClass ?>">
                <?= $btnLabel ?>
            </button>
            <?= $this->Html->link(
                'Cancelar',
                ['action' => 'view', $invoice->id],
                ['class' => 'btn btn-outline-secondary']
            ) ?>
        </div>
        <?php else: ?>
        <div class="alert alert-info mb-0">
            <i class="bi bi-info-circle me-1"></i>
            No tiene permisos de edición para esta factura en el estado actual.
        </div>
        <?php endif; ?>

        <?= $this->Form->end() ?>
    </div>
</div>
</div><!-- /columna izquierda -->

<!-- ── Columna derecha: soportes + observaciones ── -->
<div style="width:380px;flex-shrink:0;display:flex;flex-direction:column;gap:1rem;">

<?php if ($hasSoportes): ?>
<div class="card card-primary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
        <?php if ($showUploadSection): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadInvoiceDocModal">
            <i class="bi bi-upload me-1"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($documentsByStatus)): ?>
        <div style="padding:2rem 1rem;text-align:center;color:#c8c8c8;">
            <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
            <span style="font-size:.8rem;">Sin soportes adjuntos</span>
        </div>
    <?php else: ?>
        <div style="max-height:420px;overflow-y:auto;">
            <?php
            $multipleStatuses = count($documentsByStatus) > 1;
            foreach ($documentsByStatus as $status => $docs):
            ?>
            <?php if ($multipleStatuses): ?>
            <div style="padding:.3rem .875rem;background:#f8f9fa;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.4rem;">
                <span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.6rem;"><?= $statusLabels[$status] ?? $status ?></span>
                <span style="font-size:.67rem;color:#aaa;"><?= count($docs) ?> archivo<?= count($docs) !== 1 ? 's' : '' ?></span>
            </div>
            <?php endif; ?>
            <?php foreach ($docs as $doc): ?>
            <div style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">
                <!-- Icono tipo archivo -->
                <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
                    <i class="bi <?= $docIcon($doc->mime_type) ?>"
                       style="color:<?= $docIconColor($doc->mime_type) ?>;font-size:1rem;"></i>
                </div>
                <!-- Info -->
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"
                         title="<?= h($doc->document_type ?: $doc->file_name) ?>">
                        <?= h($doc->document_type ?: $doc->file_name) ?>
                    </div>
                    <?php if ($doc->document_type): ?>
                    <div style="font-size:.7rem;color:#999;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:.1rem;"
                         title="<?= h($doc->file_name) ?>"><?= h($doc->file_name) ?></div>
                    <?php endif; ?>
                    <div style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
                        <?php if (!$multipleStatuses): ?>
                        <span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.58rem;"><?= $statusLabels[$status] ?? $status ?></span>
                        <?php endif; ?>
                        <span style="font-size:.65rem;color:#bbb;">
                            <i class="bi bi-clock" style="font-size:.6rem;"></i>
                            <?= $doc->created?->format('d/m/Y H:i') ?>
                        </span>
                        <?php if ($doc->file_size): ?>
                        <span style="font-size:.63rem;color:#ccc;"><?= $this->Number->toReadableSize($doc->file_size) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Acciones -->
                <div style="display:flex;gap:.25rem;flex-shrink:0;align-self:center;">
                    <?= $this->Html->link(
                        '<i class="bi bi-box-arrow-up-right"></i>',
                        '/' . $doc->file_path,
                        ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
                    ) ?>
                    <?php if ($canDeleteDocuments && $doc->pipeline_status === $currentStatus): ?>
                    <?= $this->Form->postLink(
                        '<i class="bi bi-trash"></i>',
                        ['action' => 'deleteDocument', $invoice->id, $doc->id],
                        ['confirm' => '¿Eliminar este soporte?', 'class' => 'btn btn-sm btn-outline-danger', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'title' => 'Eliminar']
                    ) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Observaciones: chat -->
<?php $obsCount = count($invoice->invoice_observations ?? []); ?>
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
        <?php if (empty($invoice->invoice_observations)): ?>
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1.5rem 0;color:#c5c5c5;gap:.5rem;">
            <i class="bi bi-chat-square-dots" style="font-size:1.75rem;"></i>
            <span style="font-size:.78rem;">Sin observaciones aún</span>
        </div>
        <?php else: ?>
        <?php foreach ($invoice->invoice_observations as $obs):
            $isMine   = $currentUser && $obs->user_id === $currentUser->id;
            $names    = explode(' ', trim($obs->user->full_name ?? ''));
            $initials = strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[array_key_last($names)] ?? '', 0, 1));
        ?>
        <div style="display:flex;flex-direction:column;align-items:<?= $isMine ? 'flex-end' : 'flex-start' ?>;gap:.2rem;">
            <!-- Nombre -->
            <div style="font-size:.63rem;color:#aaa;font-weight:500;letter-spacing:.01em;
                        <?= $isMine ? 'padding-right:.3rem' : 'padding-left:.3rem' ?>">
                <?= $isMine ? 'Tú' : h($obs->user->full_name ?? '') ?>
            </div>
            <!-- Burbuja -->
            <div style="max-width:92%;padding:.55rem .8rem;font-size:.81rem;line-height:1.5;word-break:break-word;
                        background:<?= $isMine ? 'var(--primary-color)' : '#fff' ?>;
                        color:<?= $isMine ? '#fff' : '#2d2d2d' ?>;
                        border:1px solid <?= $isMine ? 'var(--primary-color)' : 'var(--border-color)' ?>;
                        border-radius:<?= $isMine ? '10px 10px 2px 10px' : '10px 10px 10px 2px' ?>;">
                <?= nl2br(h($obs->message)) ?>
            </div>
            <!-- Hora -->
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
        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $invoice->id]]) ?>
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

<?php if ($showUploadSection): ?>
<!-- Modal: Subir Soporte -->
<div class="modal fade" id="uploadInvoiceDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'uploadDocument', $invoice->id], 'type' => 'file']) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Subir Soporte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <?= $this->Form->control('document_type', ['class' => 'form-control', 'label' => ['text' => 'Tipo de Documento (opcional)', 'class' => 'form-label'], 'placeholder' => 'Ej. Factura, Cotización, Soporte...']) ?>
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
    // Con box-sizing:border-box de Bootstrap: height='0' fuerza recálculo correcto,
    // +2 compensa los bordes (1px top + 1px bottom) que scrollHeight excluye.
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


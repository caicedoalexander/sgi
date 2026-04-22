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
 * @var string[] $collapsibleSections
 * @var bool $isRejected
 * @var string[] $advanceErrors
 * @var string|null $nextStatus
 */

use App\Constants\InvoiceConstants;

$this->assign('title', 'Editar Factura ' . ($invoice->invoice_number ?? '#' . $invoice->id));

$documentTypes = array_combine(InvoiceConstants::DOCUMENT_TYPES, InvoiceConstants::DOCUMENT_TYPES);
$approvalOptions       = array_combine(InvoiceConstants::APPROVAL_STATUSES, InvoiceConstants::APPROVAL_STATUSES);
$dianOptions           = array_combine(InvoiceConstants::DIAN_STATUSES, InvoiceConstants::DIAN_STATUSES);
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
    'aprobacion'        => ['Aprobación',    'bg-info text-dark'],
    'contabilidad'      => ['Contabilidad',  'bg-primary'],
    'tesoreria'         => ['Tesorería',     'bg-warning text-dark'],
    'autorizacion_pago' => ['Aut. Pago',     'bg-info'],
    'pagada'            => ['Pagada',        'bg-success'],
];
$ps = $pipelineBadgeMap[$currentStatus] ?? ['Desconocido', 'bg-dark'];

// ── Ledger lookup arrays (ResultSet → array for bracket access) ──
$expenseTypesArr = is_array($expenseTypes) ? $expenseTypes : (method_exists($expenseTypes, 'toArray') ? $expenseTypes->toArray() : []);

// ── Compute section render order: editable first, read-only after ──
$sectionFieldMap = [
    'general'               => ['invoice_number', 'document_type', 'purchase_order', 'provider_id'],
    'dates'                 => ['issue_date', 'due_date'],
    'classification'        => ['operation_center_id', 'expense_type_id', 'cost_center_id', 'amount', 'detail'],
    'revision'              => ['approver_id', 'dian_validation'],
    'accounting'            => ['accrued', 'ready_for_payment'],
    'treasury'              => [],
    'payment_authorization' => [],
];
// Sections with their own internal permission logic — never skip as read-only
$functionalSections = ['treasury', 'payment_authorization'];
$editableSectionKeys = [];
$readOnlySectionKeys = [];
foreach ($visibleSections as $s) {
    if (in_array($s, $functionalSections, true) || !empty(array_intersect($sectionFieldMap[$s] ?? [], $editableFields))) {
        $editableSectionKeys[] = $s;
    } else {
        $readOnlySectionKeys[] = $s;
    }
}
// Reorder: non-collapsible editable first, then collapsible editable, then read-only
$collapsible = $collapsibleSections ?? [];
$nonCollapsibleEditable = array_filter($editableSectionKeys, fn($s) => !in_array($s, $collapsible, true));
$collapsibleEditable = array_filter($editableSectionKeys, fn($s) => in_array($s, $collapsible, true));
$renderOrder = array_merge(array_values($nonCollapsibleEditable), array_values($collapsibleEditable), $readOnlySectionKeys);
$isReadOnlySection = fn(string $s): bool => in_array($s, $readOnlySectionKeys, true);
$isCollapsibleSection = fn(string $s): bool => in_array($s, $collapsible, true);
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
$statusLabels = ['aprobacion' => 'Aprobación', 'contabilidad' => 'Contabilidad', 'tesoreria' => 'Tesorería', 'autorizacion_pago' => 'Aut. Pago', 'pagada' => 'Pagada'];
$badgeColors  = ['aprobacion' => 'bg-info text-dark', 'contabilidad' => 'bg-primary', 'tesoreria' => 'bg-warning text-dark', 'autorizacion_pago' => 'bg-info', 'pagada' => 'bg-success'];
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
?>

<!-- Layout: formulario izquierda + soportes derecha -->
<div class="sgi-invoice-layout">

<!-- ── Columna izquierda: formulario ── -->
<div class="sgi-invoice-form">
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
            'isApproved'       => $isApproved ?? false,
            'paymentStatus'    => $invoice->payment_status,
        ]) ?>
    </div>

    <!-- ── Ficha resumen (ledger) ── -->
    <?php
    $costCentersArr = is_array($costCenters) ? $costCenters : (method_exists($costCenters, 'toArray') ? $costCenters->toArray() : []);
    ?>
    <div style="padding:1rem 1.5rem .75rem;">
        <div class="sgi-ledger">
            <!-- Fila 1: Proveedor + NIT + Valor -->
            <div class="sgi-ledger-item" style="grid-column:span 2;">
                <div class="sgi-ledger-label">Proveedor</div>
                <div class="sgi-ledger-value" title="<?= h($invoice->provider->name ?? '') ?>">
                    <?= h($invoice->provider->name ?? '—') ?>
                </div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Documento</div>
                <div class="sgi-ledger-value"><?= h(($invoice->provider->document_type ?? '') . ' ' . ($invoice->provider->document_number ?? '—')) ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Valor</div>
                <div class="sgi-ledger-value --amount">
                    <?php if ($invoice->amount): ?>
                        $ <?= number_format((float)$invoice->amount, 0, ',', '.') ?>
                    <?php else: ?>
                        <span class="--muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Fila 2: Tipo Doc + Centro Op. + Tipo Gasto + Centro Costos -->
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Tipo Documento</div>
                <div class="sgi-ledger-value"><?= h($invoice->document_type ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Centro de Operación</div>
                <div class="sgi-ledger-value" title="<?= h($invoice->operation_center->name ?? '') ?>">
                    <?php if ($invoice->operation_center): ?>
                        <?= h($invoice->operation_center->code . ' - ' . $invoice->operation_center->name) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Tipo de Gasto</div>
                <div class="sgi-ledger-value"><?= h($expenseTypesArr[$invoice->expense_type_id] ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Centro de Costos</div>
                <div class="sgi-ledger-value"><?= h($costCentersArr[$invoice->cost_center_id] ?? '—') ?></div>
            </div>
            <!-- Fila 3: Fechas + Orden de Compra + Detalle -->
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Emisión</div>
                <div class="sgi-ledger-value"><?= $invoice->issue_date ? h($invoice->issue_date->format('d/m/Y')) : '—' ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Vencimiento</div>
                <div class="sgi-ledger-value"><?= $invoice->due_date ? h($invoice->due_date->format('d/m/Y')) : '—' ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Registro</div>
                <div class="sgi-ledger-value"><?= $invoice->registration_date ? h($invoice->registration_date->format('d/m/Y')) : '—' ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Orden de Compra</div>
                <div class="sgi-ledger-value"><?= h($invoice->purchase_order ?: '—') ?></div>
            </div>
        </div>
    </div>

    <div class="card-body p-4" style="padding-top:0 !important;">
        <?php if ($canSendLinks): ?>
        <?= $this->Form->create(null, [
            'url' => ['action' => 'sendApprovalLinks', $invoice->id],
            'id' => 'sendApprovalLinksForm',
            'style' => 'display:none',
        ]) ?>
        <?= $this->Form->end() ?>
        <?php endif; ?>
        <?= $this->Form->create($invoice) ?>

        <div class="sgi-form-sections">

        <?php
        $collapsibleLabels = [
            'general' => ['icon' => 'bi-file-text', 'label' => 'Documento'],
            'dates' => ['icon' => 'bi-calendar3', 'label' => 'Fechas'],
            'classification' => ['icon' => 'bi-tags', 'label' => 'Clasificación y Valor'],
        ];
        foreach ($renderOrder as $sectionName):
            $sectionIsReadOnly = $isReadOnlySection($sectionName);

            // Skip read-only sections — the ledger summary already shows reference data
            if ($sectionIsReadOnly) { continue; }

            $sectionCollapsible = $isCollapsibleSection($sectionName);
        ?>

        <?php if ($sectionCollapsible): ?>
        <details class="sgi-collapsible-section mb-4">
            <summary class="d-flex align-items-center gap-3 mb-0" style="cursor:pointer;list-style:none;">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi <?= $collapsibleLabels[$sectionName]['icon'] ?? 'bi-pencil' ?> me-1"></i><?= $collapsibleLabels[$sectionName]['label'] ?? ucfirst($sectionName) ?>
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
                <i class="bi bi-chevron-right sgi-collapse-chevron" style="font-size:.7rem;color:#bbb;transition:transform .2s;"></i>
            </summary>
            <div style="padding-top:.75rem;">
        <?php endif; ?>

        <?php if ($sectionName === 'general' && in_array('general', $visibleSections)): ?>
        <!-- ── Sección: Información del Documento ── -->
        <div class="mb-4 ">
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
                <div class="col-md-3" id="purchase-order-wrapper">
                    <label class="form-label">Orden de Compra</label>
                    <?= $this->Form->control('purchase_order', array_merge(
                        ['label' => false],
                        $canEdit('purchase_order')
                            ? ['class' => 'form-control']
                            : ['class' => 'form-control', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-3" id="provider-wrapper">
                    <label class="form-label">Proveedor</label>
                    <?= $this->Form->control('provider_id', array_merge(
                        ['label' => false, 'options' => $providers, 'empty' => '-- Seleccione --'],
                        $canEdit('provider_id')
                            ? ['class' => 'form-select select2-enable']
                            : ['class' => 'form-select select2-enable', 'disabled' => true]
                    )) ?>
                </div>
            </div>

            <!-- Documento Equivalente -->
            <div class="row g-3 mt-1">
                <div class="col-md-3">
                    <div class="form-check mt-2">
                        <?= $this->Form->checkbox('is_equivalent_document', [
                            'class' => 'form-check-input',
                            'id' => 'is-equivalent-document',
                            'disabled' => !$canEdit('document_type'),
                        ]) ?>
                        <label class="form-check-label" for="is-equivalent-document">
                            Es Documento Equivalente
                        </label>
                    </div>
                </div>
                <div class="col-md-3 <?= empty($invoice->is_equivalent_document) ? 'd-none' : '' ?>" id="holder-type-wrapper">
                    <label class="form-label">Titular del Documento</label>
                    <?= $this->Form->control('equivalent_holder_type', array_merge(
                        ['label' => false, 'options' => ['provider' => 'Proveedor', 'employee' => 'Empleado', 'manual' => 'Cédula Manual'], 'empty' => '-- Seleccione --', 'id' => 'equivalent-holder-type'],
                        $canEdit('document_type')
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-3 <?= ($invoice->equivalent_holder_type ?? '') !== 'employee' ? 'd-none' : '' ?>" id="employee-wrapper">
                    <label class="form-label">Empleado</label>
                    <?= $this->Form->control('employee_id', array_merge(
                        ['label' => false, 'options' => $employees ?? [], 'empty' => '-- Seleccione --'],
                        $canEdit('document_type')
                            ? ['class' => 'form-select select2-enable']
                            : ['class' => 'form-select select2-enable', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-3 <?= ($invoice->equivalent_holder_type ?? '') !== 'manual' ? 'd-none' : '' ?>" id="manual-doc-wrapper">
                    <label class="form-label">Cédula</label>
                    <?= $this->Form->control('manual_document_number', array_merge(
                        ['label' => false, 'placeholder' => 'Número de cédula'],
                        $canEdit('document_type')
                            ? ['class' => 'form-control']
                            : ['class' => 'form-control', 'disabled' => true]
                    )) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($sectionName === 'dates' && in_array('dates', $visibleSections)): ?>
        <!-- ── Sección: Fechas ── -->
        <div class="mb-4 ">
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
                <div class="col-md-4">
                    <label class="form-label">Fecha de Emisión</label>
                    <?php if ($canEdit('issue_date')): ?>
                        <input type="text" name="issue_date" class="form-control flatpickr-date"
                               value="<?= h($invoice->issue_date?->format('Y-m-d') ?? '') ?>">
                    <?php else: ?>
                        <input type="text" class="form-control" disabled
                               value="<?= h($invoice->issue_date?->format('d/m/Y') ?? '') ?>">
                        <input type="hidden" name="issue_date"
                               value="<?= h($invoice->issue_date?->format('Y-m-d') ?? '') ?>">
                    <?php endif; ?>
                </div>
                <div class="col-md-4" id="due-date-wrapper">
                    <label class="form-label">Fecha de Vencimiento</label>
                    <?php if ($canEdit('due_date')): ?>
                        <input type="text" name="due_date" class="form-control flatpickr-date"
                               value="<?= h($invoice->due_date?->format('Y-m-d') ?? '') ?>"
                               <?= !empty($invoice->is_equivalent_document) ? 'disabled' : '' ?>>
                    <?php else: ?>
                        <input type="text" class="form-control" disabled
                               value="<?= h($invoice->due_date?->format('d/m/Y') ?? '') ?>">
                        <input type="hidden" name="due_date"
                               value="<?= h($invoice->due_date?->format('Y-m-d') ?? '') ?>">
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($sectionName === 'classification' && in_array('classification', $visibleSections)): ?>
        <!-- ── Sección: Clasificación y Valor ── -->
        <div class="mb-4 ">
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

        <?php if ($sectionName === 'revision' && in_array('revision', $visibleSections)): ?>
        <!-- ── Sección: Revisión ── -->
        <div class="mb-4 ">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-search me-1"></i>Revisión
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <?php if (($invoice->area_approval ?? '') === \App\Constants\InvoiceConstants::APPROVAL_REJECTED): ?>
                <?php
                $rejector = null;
                foreach ($currentApprovals as $a) {
                    if ($a->status === \App\Constants\InvoiceConstants::APPROVER_STATUS_REJECTED) { $rejector = $a; break; }
                }
                ?>
                <div class="alert alert-warning mb-3" style="border:1px solid #ffc107;border-left:3px solid #CD6A15;border-radius:0;">
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi bi-exclamation-triangle-fill" style="color:#CD6A15;font-size:1.1rem;margin-top:1px;"></i>
                        <div>
                            <strong>Rechazada por <?= h($rejector->user->full_name ?? $rejector->user->username ?? 'Aprobador') ?></strong>
                            <?php if ($rejector && $rejector->observations): ?>
                                <div class="mt-1" style="font-size:.85rem;color:#555;"><?= h($rejector->observations) ?></div>
                            <?php endif; ?>
                            <div class="mt-1" style="font-size:.8rem;color:#888;">Corrija los datos y re-asigne aprobadores para reiniciar el flujo.</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Aprobadores</label>
                    <?php if ($canSendLinks): ?>
                        <select name="approver_ids[]" id="approver-ids" class="form-select select2-enable" multiple
                                form="sendApprovalLinksForm"
                                data-placeholder="Seleccione los aprobadores...">
                            <?php foreach ($approvers as $appId => $appName): ?>
                                <option value="<?= $appId ?>"><?= h($appName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" form="sendApprovalLinksForm"
                                class="btn btn-primary btn-sm mt-2"
                                onclick="return confirm('¿Enviar enlaces de aprobación a los aprobadores seleccionados?');">
                            <i class="bi bi-send me-1"></i>Enviar link de aprobación
                        </button>
                        <small class="text-muted mt-1 d-block">
                            <i class="bi bi-info-circle me-1"></i>El envío de enlaces es independiente del botón Guardar
                        </small>
                    <?php elseif ($canModifyApprovers): ?>
                        <?php if ($hasPendingApprovals): ?>
                        <div class="d-flex align-items-center gap-2 py-2">
                            <span class="spinner-border spinner-border-sm text-warning" role="status" style="width:.9rem;height:.9rem;"></span>
                            <span style="font-size:.85rem;color:#888;">Aprobaciones en curso</span>
                        </div>
                        <?php else: ?>
                        <div class="py-2" style="font-size:.85rem;color:#666;">
                            <i class="bi bi-check2-circle me-1"></i>Aprobaciones registradas
                        </div>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-warning mt-1" data-bs-toggle="modal" data-bs-target="#modifyApproversModal">
                            <i class="bi bi-pencil-square me-1"></i>Modificar aprobadores
                        </button>
                        <small class="text-muted mt-1 d-block">
                            <i class="bi bi-info-circle me-1"></i>Modificar reemplaza el conjunto y reinicia la aprobación
                        </small>
                    <?php else: ?>
                        <div class="py-2" style="font-size:.85rem;color:#aaa;">No editable en este estado</div>
                    <?php endif; ?>

                    <?php if (($invoice->area_approval ?? '') === \App\Constants\InvoiceConstants::APPROVAL_REJECTED
                        && !empty($editableFields)
                        && $currentStatus === \App\Constants\InvoiceConstants::STATUS_APROBACION): ?>
                    <form method="post" action="<?= $this->Url->build(['action' => 'resetFlow', $invoice->id]) ?>"
                          class="mt-2" onsubmit="return confirm('¿Reiniciar flujo? Se limpiarán aprobaciones y se permitirá reenviar enlaces.');">
                        <?= $this->Form->hidden('_csrfToken', ['value' => $this->request->getAttribute('csrfToken')]) ?>
                        <button type="submit" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reiniciar flujo
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
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
                <div class="col-md-3">
                    <label class="form-label">Fecha Aprobación</label>
                    <input type="text" class="form-control" disabled
                           value="<?= h($invoice->area_approval_date?->format('d/m/Y') ?? '') ?>">
                </div>
                <?php endif; ?>

                <?php if (!empty($currentApprovals)): ?>
                <div class="col-12 mt-2">
                    <?php
                    $totalApprovals = count($currentApprovals);
                    $approvedCount = 0;
                    $rejectedCount = 0;
                    $pendingCount = 0;
                    foreach ($currentApprovals as $a) {
                        match ($a->status) {
                            \App\Constants\InvoiceConstants::APPROVER_STATUS_APPROVED => $approvedCount++,
                            \App\Constants\InvoiceConstants::APPROVER_STATUS_REJECTED => $rejectedCount++,
                            default => $pendingCount++,
                        };
                    }
                    ?>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <label class="form-label mb-0">Estado de Aprobaciones</label>
                        <div class="d-flex gap-2" style="font-size:.75rem;">
                            <?php if ($approvedCount > 0): ?>
                                <span class="badge bg-success"><?= $approvedCount ?> aprobada<?= $approvedCount > 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                            <?php if ($pendingCount > 0): ?>
                                <span class="badge bg-secondary"><?= $pendingCount ?> pendiente<?= $pendingCount > 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                            <?php if ($rejectedCount > 0): ?>
                                <span class="badge bg-danger"><?= $rejectedCount ?> rechazada<?= $rejectedCount > 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
                        <?php foreach ($currentApprovals as $i => $approval): ?>
                            <?php
                            $statusIcon = match ($approval->status) {
                                \App\Constants\InvoiceConstants::APPROVER_STATUS_APPROVED => '<i class="bi bi-check-circle-fill" style="color:#469D61;"></i>',
                                \App\Constants\InvoiceConstants::APPROVER_STATUS_REJECTED => '<i class="bi bi-x-circle-fill" style="color:#dc3545;"></i>',
                                default => '<i class="bi bi-clock" style="color:#888;"></i>',
                            };
                            $borderBottom = $i < $totalApprovals - 1 ? 'border-bottom:1px solid var(--border-color);' : '';
                            ?>
                            <div class="d-flex align-items-center gap-3 px-3 py-2" style="<?= $borderBottom ?>font-size:.875rem;">
                                <div style="flex:0 0 20px;"><?= $statusIcon ?></div>
                                <div style="flex:1;min-width:0;">
                                    <div class="fw-medium"><?= h($approval->user->full_name ?? $approval->user->username) ?></div>
                                    <?php if ($approval->observations): ?>
                                        <div style="font-size:.78rem;color:#888;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= h($approval->observations) ?>">
                                            <?= h($approval->observations) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end" style="flex:0 0 auto;font-size:.78rem;color:#888;">
                                    <?= $approval->responded_at ? $approval->responded_at->format('d/m/Y H:i') : 'Pendiente' ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
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

        <?php if ($sectionName === 'accounting' && in_array('accounting', $visibleSections)): ?>
        <!-- ── Sección: Contabilidad ── -->
        <div class="mb-4 ">
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
                    <?php if ($canEdit('accrual_date')): ?>
                        <input type="text" name="accrual_date" class="form-control flatpickr-date"
                               value="<?= h($invoice->accrual_date?->format('Y-m-d') ?? '') ?>">
                    <?php else: ?>
                        <input type="text" class="form-control" disabled
                               value="<?= h($invoice->accrual_date?->format('d/m/Y') ?? '') ?>">
                        <input type="hidden" name="accrual_date"
                               value="<?= h($invoice->accrual_date?->format('Y-m-d') ?? '') ?>">
                    <?php endif; ?>
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

        <?php
            // Shared payment-section params (reused by treasury and payment_authorization)
            $sharedPaymentParams = [
                'payments'           => $invoice->invoice_payments ?? [],
                'bankingEntities'    => $bankingEntities,
                'addPaymentUrl'      => ['controller' => 'InvoicePayments', 'action' => 'addPayment', $invoice->id],
                'authorizeUrlFn'     => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'authorizePayment', $invoice->id, $pId],
                'rejectUrlFn'        => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'rejectPayment', $invoice->id, $pId],
                'deleteUrlFn'        => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'deletePayment', $invoice->id, $pId],
                'paymentStatus'      => $invoice->payment_status ?? null,
                'totalAmount'        => $invoice->amount ?? null,
                'rejectMessage'      => '¿Rechazar este pago? El registro volverá a Tesorería.',
            ];
        ?>

        <?php if ($sectionName === 'treasury' && in_array('treasury', $visibleSections)
                  && $currentStatus !== \App\Constants\InvoiceConstants::STATUS_AUTORIZACION_PAGO): ?>
        <?php
            $isTesoreriaEdit = ($roleName === \App\Constants\RoleConstants::TESORERIA || $roleName === \App\Constants\RoleConstants::ADMIN)
                && $currentStatus === \App\Constants\InvoiceConstants::STATUS_TESORERIA;
            $paymentMode = $isTesoreriaEdit ? 'tesoreria_register' : 'view';
        ?>
        <?= $this->element('payment_section', $sharedPaymentParams + [
            'canRegisterPayment' => $isTesoreriaEdit,
            'canAuthorize'       => false,
            'canDelete'          => $isTesoreriaEdit,
            'mode'               => $paymentMode,
        ]) ?>
        <?php endif; ?>

        <?php if ($sectionName === 'payment_authorization' && in_array('payment_authorization', $visibleSections)): ?>
        <?php
            $isContadorAutPago = ($roleName === \App\Constants\RoleConstants::CONTADOR || $roleName === \App\Constants\RoleConstants::ADMIN)
                && $currentStatus === \App\Constants\InvoiceConstants::STATUS_AUTORIZACION_PAGO;
            $paymentMode = $isContadorAutPago ? 'authorize' : 'view';
        ?>
        <?= $this->element('payment_section', $sharedPaymentParams + [
            'canRegisterPayment' => false,
            'canAuthorize'       => $isContadorAutPago,
            'canDelete'          => false,
            'mode'               => $paymentMode,
            'sectionTitle'       => 'Autorización de Pago',
            'sectionIcon'        => 'bi-shield-check',
        ]) ?>
        <?php endif; ?>

        <?php if ($sectionCollapsible): ?>
            </div>
        </details>
        <?php endif; ?>

        <?php endforeach; ?>

        </div><!-- /sgi-form-sections -->

        <!-- Botones de acción (sticky) -->
        <?php if (!empty($editableFields)): ?>
        <div class="sgi-sticky-actions">
            <button type="submit" class="<?= $btnClass ?>">
                <?= $btnLabel ?>
            </button>
            <?= $this->Html->link(
                'Cancelar',
                ['action' => 'view', $invoice->id],
                ['class' => 'btn btn-outline-secondary']
            ) ?>
        </div>
        <?php elseif (empty(array_intersect($functionalSections, $visibleSections))): ?>
        <div class="alert alert-info mb-0">
            <i class="bi bi-info-circle me-1"></i>
            No tiene permisos de edición para esta factura en el estado actual.
        </div>
        <?php endif; ?>

        <?= $this->Form->end() ?>

        <?php if ($currentStatus === \App\Constants\InvoiceConstants::STATUS_APROBACION && !empty($editableFields)): ?>
        <?= $this->element('invoice_edit/modify_approvers_modal', ['invoice' => $invoice, 'approvers' => $approvers]) ?>
        <?php endif; ?>
    </div>
</div>
</div><!-- /columna izquierda -->

<!-- ── Columna derecha: soportes + observaciones ── -->
<div class="sgi-invoice-sidebar">

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

    <div id="docs-empty-state" style="padding:2rem 1rem;text-align:center;color:#c8c8c8;<?= !empty($documentsByStatus) ? 'display:none;' : '' ?>">
        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
        <span style="font-size:.8rem;">Sin soportes adjuntos</span>
    </div>
    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
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
        <div class="doc-row" data-doc-id="<?= $doc->id ?>" style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">
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
                <button type="button" class="btn btn-sm btn-outline-danger doc-delete-btn"
                        data-url="<?= $this->Url->build(['action' => 'deleteDocument', $invoice->id, $doc->id]) ?>"
                        style="padding:.25rem .45rem;font-size:.72rem;line-height:1;" title="Eliminar">
                    <i class="bi bi-trash"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>

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
        <form id="obs-form" data-url="<?= $this->Url->build(['action' => 'addObservation', $invoice->id]) ?>">
        <div class="d-flex gap-2 align-items-end">
            <textarea id="obs-message" name="message" class="form-control auto-resize" rows="1"
                      style="font-size:.82rem;background:#f9fafb;border-color:var(--border-color);"
                      placeholder="Escriba una observación..."></textarea>
            <button type="submit" id="obs-send-btn" class="btn btn-primary flex-shrink-0"
                    style="padding:.5rem .75rem;align-self:flex-end;" title="Enviar">
                <i class="bi bi-send" style="font-size:.85rem;"></i>
            </button>
        </div>
        </form>
    </div>
</div>

</div><!-- /columna derecha -->

</div><!-- /layout dos columnas -->

<?php if ($showUploadSection): ?>
<!-- Modal: Subir Soporte -->
<div class="modal fade" id="uploadInvoiceDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="upload-doc-form" data-url="<?= $this->Url->build(['action' => 'uploadDocument', $invoice->id]) ?>" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Subir Soporte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Tipo de Documento (opcional)</label>
                    <input type="text" name="document_type" class="form-control" placeholder="Ej. Factura, Cotización, Soporte...">
                </div>
                <div class="mb-3">
                    <label class="form-label">Archivo</label>
                    <input type="file" name="file" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                    <div class="form-text">Máximo 20 MB — PDF, imágenes, Word o Excel.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" id="upload-doc-btn" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Subir</button>
            </div>
            </form>
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

    // AJAX observations
    var form = document.getElementById('obs-form');
    var textarea = document.getElementById('obs-message');
    var btn = document.getElementById('obs-send-btn');
    var emptyState = chat ? chat.querySelector('[style*="align-items:center"][style*="justify-content:center"]') : null;
    var obsCountBadge = document.querySelector('.sgi-folder-count');

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var message = textarea.value.trim();
            if (!message) return;

            btn.disabled = true;

            fetch(form.dataset.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
                },
                body: 'message=' + encodeURIComponent(message)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    // Remove empty state
                    if (emptyState) { emptyState.remove(); emptyState = null; }

                    // Build bubble HTML
                    var html = '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:.2rem;">'
                        + '<div style="font-size:.63rem;color:#aaa;font-weight:500;letter-spacing:.01em;padding-right:.3rem">Tú</div>'
                        + '<div style="max-width:92%;padding:.55rem .8rem;font-size:.81rem;line-height:1.5;word-break:break-word;'
                        + 'background:var(--primary-color);color:#fff;border:1px solid var(--primary-color);border-radius:10px 10px 2px 10px;">'
                        + data.observation.message.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>')
                        + '</div>'
                        + '<div style="font-size:.61rem;color:#c0c0c0;padding-right:.3rem">' + data.observation.created + '</div>'
                        + '</div>';

                    chat.insertAdjacentHTML('beforeend', html);
                    chat.scrollTop = chat.scrollHeight;

                    // Update count badge
                    var currentCount = obsCountBadge ? parseInt(obsCountBadge.textContent) || 0 : 0;
                    if (obsCountBadge) {
                        obsCountBadge.textContent = currentCount + 1;
                    } else {
                        var header = form.closest('.card').querySelector('.card-header');
                        if (header) {
                            var badge = document.createElement('span');
                            badge.className = 'sgi-folder-count ms-auto';
                            badge.textContent = '1';
                            header.appendChild(badge);
                            obsCountBadge = badge;
                        }
                    }

                    textarea.value = '';
                    syncHeight(textarea);
                } else {
                    alert(data.error || 'Error al agregar observación.');
                }
            })
            .catch(function() {
                alert('Error de conexión. Intente nuevamente.');
            })
            .finally(function() {
                btn.disabled = false;
            });
        });
    }

    // ── AJAX: Upload document ──
    var uploadForm = document.getElementById('upload-doc-form');
    var uploadBtn  = document.getElementById('upload-doc-btn');
    var docsList   = document.getElementById('docs-list');
    var docsEmpty  = document.getElementById('docs-empty-state');
    var docsCount  = document.querySelector('.sgi-folder-count');
    var csrfToken  = <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>;
    var canDelete  = <?= json_encode(!empty($canDeleteDocuments)) ?>;
    var currentStatus = <?= json_encode($currentStatus) ?>;
    var invoiceId  = <?= json_encode($invoice->id) ?>;

    var statusLabels = <?= json_encode($statusLabels) ?>;
    var badgeColors  = <?= json_encode($badgeColors) ?>;

    function docIconClass(mime) {
        mime = mime || '';
        if (mime.indexOf('pdf') !== -1) return 'bi-file-earmark-pdf';
        if (mime.indexOf('image') !== -1) return 'bi-file-earmark-image';
        if (mime.indexOf('wordprocessingml') !== -1 || mime.indexOf('msword') !== -1) return 'bi-file-earmark-word';
        if (mime.indexOf('spreadsheet') !== -1 || mime.indexOf('excel') !== -1) return 'bi-file-earmark-excel';
        return 'bi-file-earmark';
    }
    function docIconColorVal(mime) {
        mime = mime || '';
        if (mime.indexOf('pdf') !== -1) return '#dc3545';
        if (mime.indexOf('image') !== -1) return '#0dcaf0';
        if (mime.indexOf('wordprocessingml') !== -1 || mime.indexOf('msword') !== -1) return '#0d6efd';
        if (mime.indexOf('spreadsheet') !== -1 || mime.indexOf('excel') !== -1) return 'var(--primary-color)';
        return '#aaa';
    }
    function formatFileSize(bytes) {
        if (!bytes) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function updateDocsCounter(delta) {
        var el = document.querySelector('.card-header .sgi-folder-count');
        if (!el) return;
        var m = el.textContent.match(/(\d+)/);
        var n = m ? parseInt(m[1]) + delta : delta;
        if (n < 0) n = 0;
        el.textContent = n + ' doc' + (n !== 1 ? 's' : '');
    }

    function buildDocRow(doc) {
        var label = doc.document_type || doc.file_name;
        var badge = badgeColors[doc.pipeline_status] || 'bg-secondary';
        var statusLabel = statusLabels[doc.pipeline_status] || doc.pipeline_status;
        var deleteBtn = canDelete && doc.pipeline_status === currentStatus
            ? '<button type="button" class="btn btn-sm btn-outline-danger doc-delete-btn" data-url="/invoices/delete-document/' + invoiceId + '/' + doc.id + '" style="padding:.25rem .45rem;font-size:.72rem;line-height:1;" title="Eliminar"><i class="bi bi-trash"></i></button>'
            : '';

        return '<div class="doc-row" data-doc-id="' + doc.id + '" style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">'
            + '<div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">'
            + '<i class="bi ' + docIconClass(doc.mime_type) + '" style="color:' + docIconColorVal(doc.mime_type) + ';font-size:1rem;"></i></div>'
            + '<div style="flex:1;min-width:0;">'
            + '<div style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;" title="' + esc(label) + '">' + esc(label) + '</div>'
            + (doc.document_type ? '<div style="font-size:.7rem;color:#999;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:.1rem;" title="' + esc(doc.file_name) + '">' + esc(doc.file_name) + '</div>' : '')
            + '<div style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">'
            + '<span class="badge ' + badge + '" style="font-size:.58rem;">' + esc(statusLabel) + '</span>'
            + '<span style="font-size:.65rem;color:#bbb;"><i class="bi bi-clock" style="font-size:.6rem;"></i> ' + esc(doc.created) + '</span>'
            + (doc.file_size ? '<span style="font-size:.63rem;color:#ccc;">' + formatFileSize(doc.file_size) + '</span>' : '')
            + '</div></div>'
            + '<div style="display:flex;gap:.25rem;flex-shrink:0;align-self:center;">'
            + '<a href="/' + doc.file_path + '" class="btn btn-sm btn-outline-secondary" style="padding:.25rem .45rem;font-size:.72rem;line-height:1;" target="_blank" title="Abrir"><i class="bi bi-box-arrow-up-right"></i></a>'
            + deleteBtn + '</div></div>';
    }

    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var fileInput = uploadForm.querySelector('input[type="file"]');
            if (!fileInput.files.length) return;

            var file = fileInput.files[0];
            var maxBytes = window.SGI_MAX_UPLOAD_BYTES || (20 * 1024 * 1024);
            var maxLabel = window.SGI_MAX_UPLOAD_LABEL || '20 MB';
            if (file.size > maxBytes) {
                alert('El archivo supera el tamaño máximo de ' + maxLabel + '.');
                return;
            }

            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Subiendo...';

            var fd = new FormData(uploadForm);
            fetch(uploadForm.dataset.url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
                body: fd,
                redirect: 'follow'
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    if (docsEmpty) docsEmpty.style.display = 'none';
                    docsList.insertAdjacentHTML('beforeend', buildDocRow(data.document));
                    updateDocsCounter(1);
                    uploadForm.reset();
                    var modal = bootstrap.Modal.getInstance(document.getElementById('uploadInvoiceDocModal'));
                    if (modal) modal.hide();
                } else {
                    alert(data.error || 'Error al subir el archivo.');
                }
            })
            .catch(function() { alert('Error de conexión. Intente nuevamente.'); })
            .finally(function() {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = '<i class="bi bi-upload me-1"></i>Subir';
            });
        });
    }

    // ── AJAX: Delete document ──
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.doc-delete-btn');
        if (!btn) return;
        if (!confirm('¿Eliminar este soporte?')) return;

        btn.disabled = true;
        fetch(btn.dataset.url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var row = btn.closest('.doc-row');
                if (row) row.remove();
                updateDocsCounter(-1);
                if (!docsList.querySelector('.doc-row') && docsEmpty) {
                    docsEmpty.style.display = '';
                }
            } else {
                alert(data.error || 'Error al eliminar.');
                btn.disabled = false;
            }
        })
        .catch(function() {
            alert('Error de conexión. Intente nuevamente.');
            btn.disabled = false;
        });
    });
    // ── Reject payment: capture reason via prompt then POST ──
    document.querySelectorAll('.btn-reject-payment').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var reason = prompt('Motivo del rechazo (obligatorio):');
            if (reason === null) return;
            reason = reason.trim();
            if (!reason) { alert('Debe indicar un motivo.'); return; }

            var form = document.createElement('form');
            form.method = 'POST';
            form.action = btn.getAttribute('data-url');
            form.style.display = 'none';
            var csrf = document.querySelector('input[name="_csrfToken"]');
            if (csrf) {
                var ci = document.createElement('input');
                ci.type = 'hidden'; ci.name = '_csrfToken'; ci.value = csrf.value;
                form.appendChild(ci);
            }
            var ri = document.createElement('input');
            ri.type = 'hidden'; ri.name = 'reason'; ri.value = reason;
            form.appendChild(ri);
            document.body.appendChild(form);
            btn.disabled = true;
            form.submit();
        });
    });
    // ── Generic POST action buttons (authorize, reject, delete payments) ──
    document.querySelectorAll('.btn-post-action').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var msg = btn.getAttribute('data-confirm');
            if (msg && !confirm(msg)) return;

            var form = document.createElement('form');
            form.method = 'POST';
            form.action = btn.getAttribute('data-url');
            form.style.display = 'none';

            var csrf = document.querySelector('input[name="_csrfToken"]');
            if (csrf) {
                var csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_csrfToken';
                csrfInput.value = csrf.value;
                form.appendChild(csrfInput);
            }

            document.body.appendChild(form);
            btn.disabled = true;
            form.submit();
        });
    });
})();
</script>
<?php $this->end() ?>

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
use App\Constants\StatusColorConstants;

$isAdvance = ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO;

$this->assign(
    'title',
    $isAdvance
        ? ('Editar Anticipo #' . $invoice->id)
        : ('Editar Factura ' . ($invoice->invoice_number ?? '#' . $invoice->id)),
);

$documentTypes = array_combine(InvoiceConstants::DOCUMENT_TYPES, InvoiceConstants::DOCUMENT_TYPES);
$approvalOptions       = array_combine(InvoiceConstants::APPROVAL_STATUSES, InvoiceConstants::APPROVAL_STATUSES);
$dianOptions           = array_combine(InvoiceConstants::DIAN_STATUSES, InvoiceConstants::DIAN_STATUSES);
$readyForPaymentOptions = [
    ''                 => '-- Seleccione --',
    'Si'               => 'Sí',
    'Pago PSE'         => 'Pago PSE',
    'Pago prioritario' => 'Pago Prioritario',
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
    $btnClass  = 'btn btn-primary';
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

<?php if (($invoice->document_type ?? null) === \App\Constants\InvoiceConstants::DOCTYPE_LEGALIZACION && !empty($invoice->advance_id)): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-link-45deg me-1"></i>
            Esta factura es una <strong>Legalización</strong> vinculada al
            <?= $this->Html->link('Anticipo #' . h($invoice->advance_id), ['controller' => 'Advances', 'action' => 'view', $invoice->advance_id]) ?>.
        </div>
    </div>
<?php endif; ?>

<!-- Encabezado de página -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title"><?= $isAdvance ? 'Editar Anticipo' : 'Editar Factura' ?></span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            $isAdvance ? ['controller' => 'Advances', 'action' => 'index'] : ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1"></i>Ver',
            $isAdvance ? ['controller' => 'Advances', 'action' => 'view', $invoice->id] : ['action' => 'view', $invoice->id],
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
$badgeColors = StatusColorConstants::PIPELINE_STATUS_BADGES;
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
                <i class="bi <?= $isAdvance ? 'bi-cash-coin' : 'bi-receipt' ?>"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;font-family:monospace;letter-spacing:-.01em;">
                    <?= $isAdvance ? ('Anticipo #' . h($invoice->id)) : h($invoice->invoice_number ?? ('# ' . $invoice->id)) ?>
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
            <?php if ($isAdvance): ?>
            <!-- Fila 1: Beneficiario + Documento + Valor (Anticipo) -->
            <?php
            $beneficiaryName = $invoice->provider->name ?? ($invoice->employee->full_name ?? '—');
            $beneficiaryDoc = $invoice->provider->document_number
                ?? ($invoice->employee->document_number ?? null);
            $beneficiaryDocType = $invoice->provider_id
                ? ($invoice->provider->document_type ?? '')
                : ($invoice->employee_id ? ($invoice->employee->document_type ?? '') : '');
            $beneficiaryKind = $invoice->provider_id ? 'Proveedor' : ($invoice->employee_id ? 'Empleado' : '—');
            ?>
            <div class="sgi-ledger-item" style="grid-column:span 2;">
                <div class="sgi-ledger-label">Beneficiario (<?= h($beneficiaryKind) ?>)</div>
                <div class="sgi-ledger-value" title="<?= h($beneficiaryName) ?>">
                    <?= h($beneficiaryName) ?>
                </div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Documento</div>
                <div class="sgi-ledger-value"><?= h(trim($beneficiaryDocType . ' ' . ($beneficiaryDoc ?? '—'))) ?></div>
            </div>
            <?php else: ?>
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
            <?php endif; ?>
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
            <?php if (!$isAdvance): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Vencimiento</div>
                <div class="sgi-ledger-value"><?= $invoice->due_date ? h($invoice->due_date->format('d/m/Y')) : '—' ?></div>
            </div>
            <?php endif; ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Registro</div>
                <div class="sgi-ledger-value"><?= $invoice->registration_date ? h($invoice->registration_date->format('d/m/Y')) : '—' ?></div>
            </div>
            <?php if (!$isAdvance): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Orden de Compra</div>
                <div class="sgi-ledger-value"><?= h($invoice->purchase_order ?: '—') ?></div>
            </div>
            <?php endif; ?>
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
        <?= $this->Form->hidden('expected_status', ['value' => $invoice->pipeline_status]) ?>

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

        <?php if ($sectionName === 'general' && in_array('general', $visibleSections) && $isAdvance): ?>
        <!-- ── Sección: Beneficiario (Anticipo) ── -->
        <?php
        $currentBeneficiary = $invoice->provider_id ? 'provider' : ($invoice->employee_id ? 'employee' : '');
        ?>
        <div class="mb-4 ">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-person-badge me-1"></i>Beneficiario
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Tipo de Beneficiario</label>
                    <select id="beneficiary-type" class="form-select" <?= ($canEdit('provider_id') || $canEdit('employee_id')) ? '' : 'disabled' ?>>
                        <option value="">-- Seleccione --</option>
                        <option value="provider" <?= $currentBeneficiary === 'provider' ? 'selected' : '' ?>>Proveedor</option>
                        <option value="employee" <?= $currentBeneficiary === 'employee' ? 'selected' : '' ?>>Empleado</option>
                    </select>
                </div>
                <div class="col-md-9 <?= $currentBeneficiary === 'provider' ? '' : 'd-none' ?>" id="provider-wrapper">
                    <label class="form-label">Proveedor</label>
                    <?= $this->Form->control('provider_id', array_merge(
                        ['label' => false, 'options' => $providers, 'empty' => '-- Seleccione --'],
                        $canEdit('provider_id')
                            ? ['class' => 'form-select select2-enable']
                            : ['class' => 'form-select select2-enable', 'disabled' => true],
                    )) ?>
                </div>
                <div class="col-md-9 <?= $currentBeneficiary === 'employee' ? '' : 'd-none' ?>" id="employee-wrapper">
                    <label class="form-label">Empleado</label>
                    <?= $this->Form->control('employee_id', array_merge(
                        ['label' => false, 'options' => $employees ?? [], 'empty' => '-- Seleccione --'],
                        $canEdit('employee_id')
                            ? ['class' => 'form-select select2-enable']
                            : ['class' => 'form-select select2-enable', 'disabled' => true],
                    )) ?>
                </div>
            </div>
            <?= $this->Form->hidden('document_type', ['value' => InvoiceConstants::DOCTYPE_ANTICIPO]) ?>
        </div>
        <?php endif; ?>

        <?php if ($sectionName === 'general' && in_array('general', $visibleSections) && !$isAdvance): ?>
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

            <!-- Sub-formulario disparado por document_type='Recibo de Caja' -->
            <?php $isReciboDeCaja = ($invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA; ?>
            <div class="row g-3 mt-1 <?= $isReciboDeCaja ? '' : 'd-none' ?>" id="equivalent-doc-row">
                <div class="col-md-3" id="holder-type-wrapper">
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
                <?php if (!$isAdvance): ?>
                <div class="col-md-4" id="due-date-wrapper">
                    <label class="form-label">Fecha de Vencimiento</label>
                    <?php if ($canEdit('due_date')): ?>
                        <input type="text" name="due_date" class="form-control flatpickr-date"
                               value="<?= h($invoice->due_date?->format('Y-m-d') ?? '') ?>"
                               <?= ($invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA ? 'disabled' : '' ?>>
                    <?php else: ?>
                        <input type="text" class="form-control" disabled
                               value="<?= h($invoice->due_date?->format('d/m/Y') ?? '') ?>">
                        <input type="hidden" name="due_date"
                               value="<?= h($invoice->due_date?->format('Y-m-d') ?? '') ?>">
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <input type="hidden" name="due_date" value="<?= h($invoice->due_date?->format('Y-m-d') ?? '') ?>">
                <?php endif; ?>
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
                'with_idempotency_key' => true,
            ];
        ?>

        <?php if ($sectionName === 'treasury' && in_array('treasury', $visibleSections)
                  && !in_array($currentStatus, [
                      \App\Constants\InvoiceConstants::STATUS_AUTORIZACION_PAGO,
                      \App\Constants\InvoiceConstants::STATUS_PAGADA,
                  ], true)): ?>
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

        <?php if ($sectionName === 'payment_authorization' && in_array('payment_authorization', $visibleSections)
                  && in_array($currentStatus, [
                      \App\Constants\InvoiceConstants::STATUS_AUTORIZACION_PAGO,
                      \App\Constants\InvoiceConstants::STATUS_PAGADA,
                  ], true)): ?>
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
        <?php if (!empty($editableFields) || !empty($canRegress)): ?>
        <div class="sgi-sticky-actions d-flex flex-wrap gap-2 align-items-center">
            <?php if (!empty($editableFields)): ?>
                <button type="submit" class="<?= $btnClass ?>">
                    <?= $btnLabel ?>
                </button>
            <?php endif; ?>

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

            <?= $this->Html->link(
                'Cancelar',
                ['action' => 'view', $invoice->id],
                ['class' => 'btn btn-outline-secondary ms-auto']
            ) ?>
        </div>
        <?php elseif (empty(array_intersect($functionalSections, $visibleSections))): ?>
        <div class="alert alert-info mb-0">
            <i class="bi bi-info-circle me-1"></i>
            No tiene permisos de edición para esta factura en el estado actual.
        </div>
        <?php endif; ?>

        <?= $this->Form->end() ?>

        <?php if (!empty($canRegress) && empty($regressLockMessage)):
            $prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
            $currLabel = $pipelineLabels[$currentStatus] ?? $currentStatus;
        ?>
        <!-- Modal: Regresar al paso anterior -->
        <div class="modal fade" id="regressStatusModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="post"
                      action="<?= $this->Url->build(['action' => 'regressStatus', $invoice->id]) ?>"
                      id="regressStatusForm">
                    <input type="hidden" name="_csrfToken" value="<?= h($this->request->getAttribute('csrfToken')) ?>">
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
                                Esta factura volverá del paso
                                <strong><?= h($currLabel) ?></strong>
                                al paso
                                <strong><?= h($prevLabel) ?></strong>.
                            </p>
                            <div class="mb-2">
                                <label for="regressReason" class="form-label">
                                    Motivo de la regresión <span class="text-danger">*</span>
                                </label>
                                <textarea name="reason" id="regressReason"
                                          class="form-control" rows="4"
                                          required minlength="10" maxlength="500"
                                          placeholder="Describa por qué está regresando esta factura..."></textarea>
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

        <?php if ($currentStatus === \App\Constants\InvoiceConstants::STATUS_APROBACION && !empty($editableFields)): ?>
        <?= $this->element('invoice_edit/modify_approvers_modal', ['invoice' => $invoice, 'approvers' => $approvers]) ?>
        <?php endif; ?>
    </div>
</div>

<?= $this->element('email_log_panel', ['emailLogs' => $emailLogs ?? []]) ?>
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
            <?= $this->element('document_row', [
                'doc'          => $doc,
                'canDelete'    => $canDeleteDocuments && $doc->pipeline_status === $currentStatus,
                'deleteUrl'    => $this->Url->build(['action' => 'deleteDocument', $invoice->id, $doc->id]),
                'showBadge'    => !$multipleStatuses,
                'badgeColors'  => $badgeColors,
                'statusLabels' => $statusLabels,
            ]) ?>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>

<!-- Observaciones: chat -->
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
        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $invoice->id], 'id' => 'obs-form']) ?>
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

<?= $this->element('document_row_template', ['showBadge' => true]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>
<?= $this->element('observation_chat_init') ?>

<?php $this->append('script') ?>
<script>
(function(){
    // ── Documents (upload + delete) via shared helper ──
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.card.card-primary .card-header .sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadInvoiceDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>

<?php if ($isAdvance): ?>
<script>
(function () {
    var typeSelect = document.getElementById('beneficiary-type');
    if (!typeSelect) return;
    var providerWrapper = document.getElementById('provider-wrapper');
    var employeeWrapper = document.getElementById('employee-wrapper');
    if (!providerWrapper || !employeeWrapper) return;
    var providerSelect = providerWrapper.querySelector('select');
    var employeeSelect = employeeWrapper.querySelector('select');

    function applyToggle() {
        var value = typeSelect.value;
        if (value === 'provider') {
            providerWrapper.classList.remove('d-none');
            employeeWrapper.classList.add('d-none');
            employeeSelect.value = '';
            if (employeeSelect.dispatchEvent) employeeSelect.dispatchEvent(new Event('change'));
        } else if (value === 'employee') {
            employeeWrapper.classList.remove('d-none');
            providerWrapper.classList.add('d-none');
            providerSelect.value = '';
            if (providerSelect.dispatchEvent) providerSelect.dispatchEvent(new Event('change'));
        } else {
            providerWrapper.classList.add('d-none');
            employeeWrapper.classList.add('d-none');
        }
    }

    typeSelect.addEventListener('change', applyToggle);
})();
</script>
<?php endif; ?>
<?php $this->end() ?>

<?php $this->append('script') ?>
<script>
(function () {
    var docTypeSelect = document.querySelector('select[name="document_type"]');
    if (!docTypeSelect) return;

    var equivalentRow = document.getElementById('equivalent-doc-row');
    var holderSelect  = document.getElementById('equivalent-holder-type');
    var employeeWrap  = document.getElementById('employee-wrapper');
    var manualWrap    = document.getElementById('manual-doc-wrapper');
    var providerWrap  = document.getElementById('provider-wrapper');
    var dueDateInput  = document.querySelector('input[name="due_date"]');
    // Si el form es Anticipo el provider-wrapper lo gobierna otro toggle (beneficiary-type),
    // no debemos tocarlo aquí.
    var hasBeneficiaryToggle = document.getElementById('beneficiary-type') !== null;

    function setVisible(wrapper, visible) {
        if (!wrapper) return;
        wrapper.classList.toggle('d-none', !visible);
        wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
            el.disabled = !visible;
            if (!visible) {
                el.value = '';
                el.checked = false;
                // Reset Select2 widget si aplica (sin esto el label cacheado queda visible).
                if (window.jQuery && el.tagName === 'SELECT' && window.jQuery(el).hasClass('select2-hidden-accessible')) {
                    window.jQuery(el).val(null).trigger('change');
                }
            }
        });
    }

    function applyHolderRules() {
        var holder = holderSelect ? holderSelect.value : '';
        setVisible(employeeWrap, holder === 'employee');
        setVisible(manualWrap,   holder === 'manual');
        if (!hasBeneficiaryToggle) {
            // Proveedor visible sólo cuando el titular es 'provider' o aún no se ha elegido.
            setVisible(providerWrap, holder === '' || holder === 'provider');
        }
    }

    function applyDocTypeRules() {
        var isReciboDeCaja = docTypeSelect.value === <?= json_encode(InvoiceConstants::DOCTYPE_RECIBO_CAJA) ?>;

        setVisible(equivalentRow, isReciboDeCaja);

        if (dueDateInput) {
            dueDateInput.disabled = isReciboDeCaja;
            if (isReciboDeCaja) dueDateInput.value = '';
        }

        if (!isReciboDeCaja) {
            if (holderSelect) holderSelect.value = '';
            setVisible(employeeWrap, false);
            setVisible(manualWrap,   false);
            if (!hasBeneficiaryToggle) setVisible(providerWrap, true);
        } else {
            applyHolderRules();
        }
    }

    docTypeSelect.addEventListener('change', applyDocTypeRules);
    if (holderSelect) holderSelect.addEventListener('change', applyHolderRules);
    // Asimetría intencional con add.php: no llamamos applyDocTypeRules() en load.
    // Razón: el server rehidrata el DOM con clases d-none correctas y respeta la policy
    // (campos disabled cuando $canEdit('document_type') === false). Llamar setVisible()
    // forzaría disabled=false sobre campos que la policy debe mantener bloqueados.
})();
</script>
<?php $this->end() ?>

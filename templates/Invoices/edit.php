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
    $btnClass = 'btn btn-warning';
} elseif ($canAdvance && empty($advanceErrors) && $nextStatus) {
    $nextLabel = $pipelineLabels[$nextStatus] ?? $nextStatus;
    $btnLabel  = '<i class="bi bi-arrow-right-circle me-1"></i>Guardar y Avanzar a: ' . h($nextLabel);
    $btnClass  = 'btn btn-success';
} else {
    $btnLabel = '<i class="bi bi-save me-1"></i>Guardar Cambios';
    $btnClass = 'btn btn-warning';
}

$pipelineBadgeMap = [
    'revision'      => ['Revisión',      'bg-secondary'],
    'area_approved' => ['Área Aprobada', 'bg-info text-dark'],
    'accrued'       => ['Causada',       'bg-primary'],
    'treasury'      => ['Tesorería',     'bg-warning text-dark'],
    'paid'          => ['Pagada',        'bg-success'],
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
            ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1"></i>Ver',
            ['action' => 'view', $invoice->id],
            ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]
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
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
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
                <?php foreach ([
                    'registration_date' => 'Fecha de Registro',
                    'issue_date'        => 'Fecha de Emisión',
                    'due_date'          => 'Fecha de Vencimiento',
                ] as $field => $label): ?>
                <div class="col-md-4">
                    <label class="form-label"><?= $label ?></label>
                    <?php if ($canEdit($field)): ?>
                        <input type="text" name="<?= $field ?>" class="form-control flatpickr-date"
                               value="<?= h($invoice->$field?->format('Y-m-d') ?? '') ?>">
                    <?php else: ?>
                        <input type="text" class="form-control" disabled
                               value="<?= h($invoice->$field ? $this->formatDateEs($invoice->$field) : '') ?>">
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
                    ['label' => false, 'type' => 'textarea', 'rows' => 3],
                    $canEdit('detail')
                        ? ['class' => 'form-control']
                        : ['class' => 'form-control', 'disabled' => true]
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
                    <?= $this->Form->control('area_approval', array_merge(
                        ['label' => false, 'options' => $approvalOptions],
                        $canEdit('area_approval')
                            ? ['class' => 'form-select']
                            : ['class' => 'form-select', 'disabled' => true]
                    )) ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Fecha Aprobación</label>
                    <?php if ($canEdit('area_approval_date')): ?>
                        <input type="text" name="area_approval_date" class="form-control flatpickr-date"
                               value="<?= h($invoice->area_approval_date?->format('Y-m-d') ?? '') ?>">
                    <?php else: ?>
                        <input type="text" class="form-control" disabled
                               value="<?= h($invoice->area_approval_date ? $this->formatDateEs($invoice->area_approval_date) : '') ?>">
                    <?php endif; ?>
                </div>
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
                    <div class="form-check mt-1">
                        <?= $this->Form->control('accrued', array_merge(
                            ['type' => 'checkbox', 'label' => 'Marcar como causada'],
                            $canEdit('accrued')
                                ? ['class' => 'form-check-input']
                                : ['class' => 'form-check-input', 'disabled' => true]
                        )) ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Fecha de Causación</label>
                    <?php if ($canEdit('accrual_date')): ?>
                        <input type="text" name="accrual_date" class="form-control flatpickr-date"
                               value="<?= h($invoice->accrual_date?->format('Y-m-d') ?? '') ?>">
                    <?php else: ?>
                        <input type="text" class="form-control" disabled
                               value="<?= h($invoice->accrual_date ? $this->formatDateEs($invoice->accrual_date) : '') ?>">
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
                               value="<?= h($invoice->payment_date ? $this->formatDateEs($invoice->payment_date) : '') ?>">
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Observaciones (siempre visible) ── -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">Observaciones</span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <?= $this->Form->control('observations', array_merge(
                ['label' => false, 'type' => 'textarea', 'rows' => 2],
                $canEdit('observations')
                    ? ['class' => 'form-control']
                    : ['class' => 'form-control', 'disabled' => true]
            )) ?>
        </div>

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

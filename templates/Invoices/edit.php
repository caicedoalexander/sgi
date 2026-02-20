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
$this->assign('title', 'Editar Factura #' . $invoice->id);

$documentTypes = [
    'Factura' => 'Factura',
    'Nota Debito' => 'Nota Débito',
    'Caja menor' => 'Caja menor',
    'Tarjeta de Crédito' => 'Tarjeta de Crédito',
    'Reintegro' => 'Reintegro',
    'Legalización' => 'Legalización',
    'Recibo' => 'Recibo',
    'Anticipo' => 'Anticipo',
];
$approvalOptions = ['Pendiente' => 'Pendiente', 'Aprobada' => 'Aprobada', 'Rechazada' => 'Rechazada'];
$dianOptions = ['Pendiente' => 'Pendiente', 'Aprobada' => 'Aprobada', 'Rechazado' => 'Rechazado'];
$readyForPaymentOptions = [
    '' => '-- Seleccione --',
    'Si' => 'Sí',
    'No' => 'No',
    'Anticipo Empleado' => 'Anticipo Empleado',
    'Anticipo Proveedor' => 'Anticipo Proveedor',
    'Pago prioritario' => 'Pago prioritario',
    'Pago PSE' => 'Pago PSE',
    'No Legalización' => 'No Legalización',
    'Reintegro' => 'Reintegro',
];
$paymentStatusOptions = ['' => '-- Seleccione --', 'Pago total' => 'Pago total', 'Pago Parcial' => 'Pago Parcial'];

// Helper: is a field editable for this role/status?
$canEdit = fn(string $field): bool => in_array($field, $editableFields, true);

// Determine submit button label
if ($isRejected) {
    $btnLabel = '<i class="bi bi-save me-1"></i>Guardar Cambios';
    $btnClass = 'btn btn-warning';
} elseif ($canAdvance && empty($advanceErrors) && $nextStatus) {
    $nextLabel = $pipelineLabels[$nextStatus] ?? $nextStatus;
    $btnLabel  = '<i class="bi bi-arrow-right-circle me-1"></i>Guardar y Avanzar a: ' . h($nextLabel);
    $btnClass  = 'btn btn-success';
} elseif ($canAdvance && !empty($advanceErrors)) {
    $btnLabel = '<i class="bi bi-save me-1"></i>Guardar Cambios';
    $btnClass = 'btn btn-warning';
} else {
    $btnLabel = '<i class="bi bi-save me-1"></i>Guardar Cambios';
    $btnClass = 'btn btn-warning';
}

$pipelineBadges = [
    'revision'     => 'bg-secondary',
    'area_approved' => 'bg-info text-dark',
    'accrued'      => 'bg-primary',
    'treasury'     => 'bg-warning text-dark',
    'paid'         => 'bg-success',
];
?>
<div class="mb-4 d-flex gap-2 align-items-center flex-wrap">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
    <?= $this->Html->link('<i class="bi bi-eye me-1"></i>Ver', ['action' => 'view', $invoice->id], ['class' => 'btn btn-outline-info btn-sm', 'escape' => false]) ?>
    <span class="ms-auto text-muted small">
        Rol: <strong><?= h($roleName) ?></strong>
    </span>
</div>

<!-- Pipeline visual -->
<?= $this->element('pipeline_progress', [
    'currentStatus'  => $currentStatus,
    'pipelineStatuses' => $pipelineStatuses,
    'pipelineLabels' => $pipelineLabels,
    'isRejected'     => $isRejected,
    'paymentStatus'  => $invoice->payment_status,
]) ?>

<?php if ($canAdvance && !$isRejected && !empty($advanceErrors)): ?>
<div class="alert alert-warning py-2 mb-3">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>Para avanzar al siguiente estado complete:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($advanceErrors as $err): ?>
            <li><?= h($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Factura #<?= $invoice->id ?></h5>
        <span class="badge <?= $pipelineBadges[$currentStatus] ?? 'bg-dark' ?> fs-6">
            <?= h($pipelineLabels[$currentStatus] ?? $currentStatus) ?>
        </span>
    </div>
    <div class="card-body">
        <?= $this->Form->create($invoice) ?>

        <?php if (in_array('general', $visibleSections)): ?>
        <!-- Información del Documento -->
        <h6 class="text-muted mb-3 mt-2">
            <i class="bi bi-file-text me-1"></i>Información del Documento
        </h6>
        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label">No. Factura</label>
                <?= $this->Form->control('invoice_number', array_merge(
                    ['label' => false, 'placeholder' => 'Ej: FV-001234'],
                    $canEdit('invoice_number') ? ['class' => 'form-control'] : ['class' => 'form-control bg-light', 'disabled' => true]
                )) ?>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Tipo de Documento</label>
                <?= $this->Form->control('document_type', array_merge(
                    ['label' => false, 'options' => $documentTypes],
                    $canEdit('document_type') ? ['class' => 'form-select'] : ['class' => 'form-select bg-light', 'disabled' => true]
                )) ?>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Orden de Compra</label>
                <?= $this->Form->control('purchase_order', array_merge(
                    ['label' => false],
                    $canEdit('purchase_order') ? ['class' => 'form-control'] : ['class' => 'form-control bg-light', 'disabled' => true]
                )) ?>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Proveedor</label>
                <?= $this->Form->control('provider_id', array_merge(
                    ['label' => false, 'options' => $providers, 'empty' => '-- Seleccione --'],
                    $canEdit('provider_id') ? ['class' => 'form-select'] : ['class' => 'form-select bg-light', 'disabled' => true]
                )) ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (in_array('dates', $visibleSections)): ?>
        <!-- Fechas -->
        <h6 class="text-muted mb-3">
            <i class="bi bi-calendar3 me-1"></i>Fechas
        </h6>
        <div class="row">
            <?php foreach (['registration_date' => 'Fecha de Registro', 'issue_date' => 'Fecha de Emisión', 'due_date' => 'Fecha de Vencimiento'] as $field => $label): ?>
            <div class="col-md-4 mb-3">
                <label class="form-label"><?= $label ?></label>
                <?php if ($canEdit($field)): ?>
                    <input type="text" name="<?= $field ?>" class="form-control flatpickr-date"
                           value="<?= h($invoice->$field?->format('Y-m-d') ?? '') ?>">
                <?php else: ?>
                    <input type="text" class="form-control bg-light"
                           value="<?= h($invoice->$field ? $this->formatDateEs($invoice->$field) : '') ?>" disabled>
                    <input type="hidden" name="<?= $field ?>" value="<?= h($invoice->$field?->format('Y-m-d') ?? '') ?>">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (in_array('classification', $visibleSections)): ?>
        <!-- Clasificación y Valor -->
        <h6 class="text-muted mb-3">
            <i class="bi bi-tags me-1"></i>Clasificación y Valor
        </h6>
        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label">Centro de Operación</label>
                <?= $this->Form->control('operation_center_id', array_merge(
                    ['label' => false, 'options' => $operationCenters, 'empty' => '-- Seleccione --'],
                    $canEdit('operation_center_id') ? ['class' => 'form-select'] : ['class' => 'form-select bg-light', 'disabled' => true]
                )) ?>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Tipo de Gasto</label>
                <?= $this->Form->control('expense_type_id', array_merge(
                    ['label' => false, 'options' => $expenseTypes, 'empty' => '-- Seleccione --'],
                    $canEdit('expense_type_id') ? ['class' => 'form-select'] : ['class' => 'form-select bg-light', 'disabled' => true]
                )) ?>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Centro de Costos</label>
                <?= $this->Form->control('cost_center_id', array_merge(
                    ['label' => false, 'options' => $costCenters, 'empty' => '-- Seleccione --'],
                    $canEdit('cost_center_id') ? ['class' => 'form-select'] : ['class' => 'form-select bg-light', 'disabled' => true]
                )) ?>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Valor (COP)</label>
                <?php if ($canEdit('amount')): ?>
                    <input type="text" name="amount" class="form-control currency-input"
                           value="<?= h($invoice->amount ?? '') ?>">
                <?php else: ?>
                    <input type="text" class="form-control bg-light"
                           value="$ <?= number_format((float)($invoice->amount ?? 0), 0, ',', '.') ?>" disabled>
                <?php endif; ?>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Detalle</label>
            <?= $this->Form->control('detail', array_merge(
                ['label' => false, 'type' => 'textarea', 'rows' => 3],
                $canEdit('detail') ? ['class' => 'form-control'] : ['class' => 'form-control bg-light', 'disabled' => true]
            )) ?>
        </div>
        <?php endif; ?>

        <?php if (in_array('revision', $visibleSections)): ?>
        <hr>
        <!-- Revisión -->
        <h6 class="text-muted mb-3">
            <i class="bi bi-search me-1"></i>Revisión
        </h6>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Aprobador</label>
                <?= $this->Form->control('approver_id', array_merge(
                    ['label' => false, 'options' => $approvers, 'empty' => '-- Seleccione --'],
                    $canEdit('approver_id') ? ['class' => 'form-select'] : ['class' => 'form-select bg-light', 'disabled' => true]
                )) ?>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Aprobación Área</label>
                <?= $this->Form->control('area_approval', array_merge(
                    ['label' => false, 'options' => $approvalOptions],
                    $canEdit('area_approval') ? ['class' => 'form-select'] : ['class' => 'form-select bg-light', 'disabled' => true]
                )) ?>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Fecha Aprobación</label>
                <?php if ($canEdit('area_approval_date')): ?>
                    <input type="text" name="area_approval_date" class="form-control flatpickr-date"
                           value="<?= h($invoice->area_approval_date?->format('Y-m-d') ?? '') ?>">
                <?php else: ?>
                    <input type="text" class="form-control bg-light"
                           value="<?= h($invoice->area_approval_date ? $this->formatDateEs($invoice->area_approval_date) : '') ?>" disabled>
                <?php endif; ?>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Validación DIAN</label>
                <?= $this->Form->control('dian_validation', array_merge(
                    ['label' => false, 'options' => $dianOptions],
                    $canEdit('dian_validation') ? ['class' => 'form-select'] : ['class' => 'form-select bg-light', 'disabled' => true]
                )) ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (in_array('accounting', $visibleSections)): ?>
        <hr>
        <!-- Contabilidad -->
        <h6 class="text-muted mb-3">
            <i class="bi bi-calculator me-1"></i>Contabilidad
        </h6>
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="form-check mt-4">
                    <?= $this->Form->control('accrued', array_merge(
                        ['type' => 'checkbox', 'label' => 'Causada'],
                        $canEdit('accrued')
                            ? ['class' => 'form-check-input']
                            : ['class' => 'form-check-input', 'disabled' => true]
                    )) ?>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Fecha de Causación</label>
                <?php if ($canEdit('accrual_date')): ?>
                    <input type="text" name="accrual_date" class="form-control flatpickr-date"
                           value="<?= h($invoice->accrual_date?->format('Y-m-d') ?? '') ?>">
                <?php else: ?>
                    <input type="text" class="form-control bg-light"
                           value="<?= h($invoice->accrual_date ? $this->formatDateEs($invoice->accrual_date) : '') ?>" disabled>
                <?php endif; ?>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Lista para Pago</label>
                <?= $this->Form->control('ready_for_payment', array_merge(
                    ['label' => false, 'options' => $readyForPaymentOptions],
                    $canEdit('ready_for_payment') ? ['class' => 'form-select'] : ['class' => 'form-select bg-light', 'disabled' => true]
                )) ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (in_array('treasury', $visibleSections)): ?>
        <hr>
        <!-- Tesorería -->
        <h6 class="text-muted mb-3">
            <i class="bi bi-bank me-1"></i>Tesorería
        </h6>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Estado de Pago</label>
                <?= $this->Form->control('payment_status', array_merge(
                    ['label' => false, 'options' => $paymentStatusOptions],
                    $canEdit('payment_status') ? ['class' => 'form-select'] : ['class' => 'form-select bg-light', 'disabled' => true]
                )) ?>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Fecha de Pago</label>
                <?php if ($canEdit('payment_date')): ?>
                    <input type="text" name="payment_date" class="form-control flatpickr-date"
                           value="<?= h($invoice->payment_date?->format('Y-m-d') ?? '') ?>">
                <?php else: ?>
                    <input type="text" class="form-control bg-light"
                           value="<?= h($invoice->payment_date ? $this->formatDateEs($invoice->payment_date) : '') ?>" disabled>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <hr>
        <div class="mb-4">
            <label class="form-label">Observaciones</label>
            <?= $this->Form->control('observations', array_merge(
                ['label' => false, 'type' => 'textarea', 'rows' => 2],
                $canEdit('observations') ? ['class' => 'form-control'] : ['class' => 'form-control bg-light', 'disabled' => true]
            )) ?>
        </div>

        <?php if (!empty($editableFields)): ?>
            <button type="submit" class="<?= $btnClass ?>">
                <?= $btnLabel ?>
            </button>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-1"></i>
                No tiene permisos de edición para esta factura en el estado actual.
            </div>
        <?php endif; ?>

        <?= $this->Form->end() ?>
    </div>
</div>

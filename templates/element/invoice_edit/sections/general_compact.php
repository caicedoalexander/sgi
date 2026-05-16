<?php
/**
 * Datos Generales — versión compacta consolidada.
 * Reemplaza los partials general.php / general_advance.php / dates.php /
 * classification.php por un único grid 4-col alineado al spec
 * editar-factura.jsx (Datos Generales).
 *
 * Layout:
 *   Row 1: Proveedor/Beneficiario (span 2) · No. Factura · Valor
 *   Row 2: Tipo Documento · Centro Operación · Tipo Gasto · Centro Costos
 *   Row 3: Emisión · Vencimiento · Registro (RO) · Orden de Compra
 *   Detalle: span 4
 *   Sub-form Recibo de Caja (solo si aplica): span 4 con holder + employee/manual
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var callable $canEdit
 * @var bool $isAdvance
 * @var array<string,string> $documentTypes
 */

use App\Constants\InvoiceConstants;

$isReciboDeCaja = ($viewModel->invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA;
$currentBeneficiary = $viewModel->invoice->provider_id
    ? 'provider'
    : ($viewModel->invoice->employee_id ? 'employee' : '');
?>
<div class="sgi-edit-fields-grid">

    <?php /* ─── Row 1 ─────────────────────────────────────────────── */ ?>

    <?php if ($isAdvance): ?>
        <?php /* Anticipo: tipo de beneficiario + proveedor o empleado (span 2 + span 1 cada uno) */ ?>
        <div class="sgi-edit-field">
            <label class="form-label" for="beneficiary-type">Tipo de Beneficiario</label>
            <select id="beneficiary-type" class="form-select" <?= ($canEdit('provider_id') || $canEdit('employee_id')) ? '' : 'disabled' ?>>
                <option value="">-- Seleccione --</option>
                <option value="provider" <?= $currentBeneficiary === 'provider' ? 'selected' : '' ?>>Proveedor</option>
                <option value="employee" <?= $currentBeneficiary === 'employee' ? 'selected' : '' ?>>Empleado</option>
            </select>
        </div>
        <div class="sgi-edit-field sgi-edit-field--span2 <?= $currentBeneficiary === 'provider' ? '' : 'd-none' ?>" id="provider-wrapper">
            <label class="form-label">Proveedor</label>
            <?= $this->Form->control('provider_id', array_merge(
                ['label' => false, 'options' => $viewModel->providers, 'empty' => '-- Seleccione --'],
                $canEdit('provider_id')
                    ? ['class' => 'form-select select2-enable']
                    : ['class' => 'form-select select2-enable', 'disabled' => true],
            )) ?>
        </div>
        <div class="sgi-edit-field sgi-edit-field--span2 <?= $currentBeneficiary === 'employee' ? '' : 'd-none' ?>" id="employee-wrapper">
            <label class="form-label">Empleado</label>
            <?= $this->Form->control('employee_id', array_merge(
                ['label' => false, 'options' => $viewModel->employees ?? [], 'empty' => '-- Seleccione --'],
                $canEdit('employee_id')
                    ? ['class' => 'form-select select2-enable']
                    : ['class' => 'form-select select2-enable', 'disabled' => true],
            )) ?>
        </div>
        <?= $this->Form->hidden('document_type', ['value' => InvoiceConstants::DOCTYPE_ANTICIPO]) ?>
    <?php else: ?>
        <?php /* Factura: Proveedor (span 2) · No. Factura · Valor */ ?>
        <div class="sgi-edit-field sgi-edit-field--span2" id="provider-wrapper">
            <label class="form-label">Proveedor</label>
            <?= $this->Form->control('provider_id', array_merge(
                ['label' => false, 'options' => $viewModel->providers, 'empty' => '-- Seleccione --'],
                $canEdit('provider_id')
                    ? ['class' => 'form-select select2-enable']
                    : ['class' => 'form-select select2-enable', 'disabled' => true]
            )) ?>
        </div>
        <div class="sgi-edit-field">
            <label class="form-label">No. Factura</label>
            <?= $this->Form->control('invoice_number', array_merge(
                ['label' => false, 'placeholder' => 'Ej: FV-001234'],
                $canEdit('invoice_number')
                    ? ['class' => 'form-control mono']
                    : ['class' => 'form-control mono', 'disabled' => true]
            )) ?>
        </div>
    <?php endif; ?>

    <div class="sgi-edit-field">
        <label class="form-label">Valor (COP)</label>
        <?php if ($canEdit('amount')): ?>
            <input type="text" name="amount" class="form-control currency-input"
                   value="<?= h($viewModel->invoice->amount ?? '') ?>">
        <?php else: ?>
            <input type="text" class="form-control"
                   value="$ <?= number_format((float)($viewModel->invoice->amount ?? 0), 0, ',', '.') ?>"
                   disabled>
        <?php endif; ?>
    </div>

    <?php /* ─── Row 2 ─────────────────────────────────────────────── */ ?>

    <?php if (!$isAdvance): ?>
    <div class="sgi-edit-field">
        <label class="form-label">Tipo Documento</label>
        <?= $this->Form->control('document_type', array_merge(
            ['label' => false, 'options' => $documentTypes],
            $canEdit('document_type')
                ? ['class' => 'form-select']
                : ['class' => 'form-select', 'disabled' => true]
        )) ?>
    </div>
    <?php endif; ?>

    <div class="sgi-edit-field">
        <label class="form-label">Centro de Operación</label>
        <?= $this->Form->control('operation_center_id', array_merge(
            ['label' => false, 'options' => $viewModel->operationCenters, 'empty' => '-- Seleccione --'],
            ($canEdit('operation_center_id') && !$isAdvance)
                ? ['class' => 'form-select']
                : ['class' => 'form-select', 'disabled' => true]
        )) ?>
    </div>
    <div class="sgi-edit-field">
        <label class="form-label">Tipo de Gasto</label>
        <?= $this->Form->control('expense_type_id', array_merge(
            ['label' => false, 'options' => $viewModel->expenseTypes, 'empty' => '-- Seleccione --'],
            $canEdit('expense_type_id')
                ? ['class' => 'form-select']
                : ['class' => 'form-select', 'disabled' => true]
        )) ?>
    </div>
    <div class="sgi-edit-field">
        <label class="form-label">Centro de Costos</label>
        <?= $this->Form->control('cost_center_id', array_merge(
            ['label' => false, 'options' => $viewModel->costCenters, 'empty' => '-- Seleccione --'],
            $canEdit('cost_center_id')
                ? ['class' => 'form-select']
                : ['class' => 'form-select', 'disabled' => true]
        )) ?>
    </div>

    <?php /* ─── Row 3 ─────────────────────────────────────────────── */ ?>

    <div class="sgi-edit-field">
        <label class="form-label">Fecha de Emisión</label>
        <?php if ($canEdit('issue_date')): ?>
            <input type="text" name="issue_date" class="form-control flatpickr-date"
                   value="<?= h($viewModel->invoice->issue_date?->format('Y-m-d') ?? '') ?>">
        <?php else: ?>
            <input type="text" class="form-control" disabled
                   value="<?= h($viewModel->invoice->issue_date?->format('d/m/Y') ?? '') ?>">
            <input type="hidden" name="issue_date"
                   value="<?= h($viewModel->invoice->issue_date?->format('Y-m-d') ?? '') ?>">
        <?php endif; ?>
    </div>

    <?php if (!$isAdvance): ?>
    <div class="sgi-edit-field" id="due-date-wrapper">
        <label class="form-label">Fecha de Vencimiento</label>
        <?php if ($canEdit('due_date')): ?>
            <input type="text" name="due_date" class="form-control flatpickr-date"
                   value="<?= h($viewModel->invoice->due_date?->format('Y-m-d') ?? '') ?>"
                   <?= $isReciboDeCaja ? 'disabled' : '' ?>>
        <?php else: ?>
            <input type="text" class="form-control" disabled
                   value="<?= h($viewModel->invoice->due_date?->format('d/m/Y') ?? '') ?>">
            <input type="hidden" name="due_date"
                   value="<?= h($viewModel->invoice->due_date?->format('Y-m-d') ?? '') ?>">
        <?php endif; ?>
    </div>
    <?php else: ?>
        <?= $this->Form->hidden('due_date', ['value' => $viewModel->invoice->due_date?->format('Y-m-d') ?? '']) ?>
    <?php endif; ?>

    <div class="sgi-edit-field">
        <label class="form-label">Fecha de Registro</label>
        <input type="text" class="form-control" disabled
               value="<?= h($viewModel->invoice->registration_date?->format('d/m/Y') ?? '') ?>">
        <input type="hidden" name="registration_date"
               value="<?= h($viewModel->invoice->registration_date?->format('Y-m-d') ?? '') ?>">
    </div>

    <?php if (!$isAdvance): ?>
    <div class="sgi-edit-field" id="purchase-order-wrapper">
        <label class="form-label">Orden de Compra</label>
        <?= $this->Form->control('purchase_order', array_merge(
            ['label' => false, 'placeholder' => '—'],
            $canEdit('purchase_order')
                ? ['class' => 'form-control mono']
                : ['class' => 'form-control mono', 'disabled' => true]
        )) ?>
    </div>
    <?php endif; ?>

    <?php /* ─── Detalle (span 4) ─────────────────────────────────── */ ?>
    <div class="sgi-edit-field sgi-edit-field--span4">
        <label class="form-label">Detalle</label>
        <?= $this->Form->control('detail', array_merge(
            ['label' => false, 'type' => 'textarea', 'rows' => 1],
            $canEdit('detail')
                ? ['class' => 'form-control auto-resize']
                : ['class' => 'form-control auto-resize', 'disabled' => true]
        )) ?>
    </div>

    <?php /* ─── Sub-form Recibo de Caja (titular + variantes) ───── */ ?>
    <?php if (!$isAdvance): ?>
    <div class="sgi-edit-field sgi-edit-field--span4 <?= $isReciboDeCaja ? '' : 'd-none' ?>" id="equivalent-doc-row">
        <div class="sgi-edit-fields-grid">
            <div class="sgi-edit-field" id="holder-type-wrapper">
                <label class="form-label">Titular del Documento</label>
                <?= $this->Form->control('equivalent_holder_type', array_merge(
                    ['label' => false, 'options' => ['provider' => 'Proveedor', 'employee' => 'Empleado', 'manual' => 'Cédula Manual'], 'empty' => '-- Seleccione --', 'id' => 'equivalent-holder-type'],
                    $canEdit('document_type')
                        ? ['class' => 'form-select']
                        : ['class' => 'form-select', 'disabled' => true]
                )) ?>
            </div>
            <div class="sgi-edit-field <?= ($viewModel->invoice->equivalent_holder_type ?? '') !== 'employee' ? 'd-none' : '' ?>" id="employee-wrapper">
                <label class="form-label">Empleado</label>
                <?= $this->Form->control('employee_id', array_merge(
                    ['label' => false, 'options' => $viewModel->employees ?? [], 'empty' => '-- Seleccione --'],
                    $canEdit('document_type')
                        ? ['class' => 'form-select select2-enable']
                        : ['class' => 'form-select select2-enable', 'disabled' => true]
                )) ?>
            </div>
            <div class="sgi-edit-field <?= ($viewModel->invoice->equivalent_holder_type ?? '') !== 'manual' ? 'd-none' : '' ?>" id="manual-doc-wrapper">
                <label class="form-label">Cédula</label>
                <?= $this->Form->control('manual_document_number', array_merge(
                    ['label' => false, 'placeholder' => 'Número de cédula'],
                    $canEdit('document_type')
                        ? ['class' => 'form-control mono']
                        : ['class' => 'form-control mono', 'disabled' => true]
                )) ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

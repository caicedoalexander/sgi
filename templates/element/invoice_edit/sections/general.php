<?php
/**
 * Sección "Documento" (Factura no-Anticipo). Incluye sub-form de
 * Recibo de Caja con titular provider/employee/manual.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var callable $canEdit
 * @var bool $isAdvance
 * @var array<string,string> $documentTypes
 */

use App\Constants\InvoiceConstants;

$isReciboDeCaja = ($viewModel->invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA;
?>
<div class="mb-4 ">
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="text-uppercase fw-semibold flex-shrink-0"
              style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
            <i class="bi bi-file-text me-1" aria-hidden="true"></i>Documento
        </span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">No. Factura</label>
            <?= $this->Form->control('invoice_number', array_merge(
                ['label' => false, 'placeholder' => 'Ej: FV-001234'],
                ($canEdit('invoice_number') && !$isAdvance)
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
                ['label' => false, 'options' => $viewModel->providers, 'empty' => '-- Seleccione --'],
                $canEdit('provider_id')
                    ? ['class' => 'form-select select2-enable']
                    : ['class' => 'form-select select2-enable', 'disabled' => true]
            )) ?>
        </div>
    </div>

    <!-- Sub-formulario disparado por document_type='Recibo de Caja' -->
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
        <div class="col-md-3 <?= ($viewModel->invoice->equivalent_holder_type ?? '') !== 'employee' ? 'd-none' : '' ?>" id="employee-wrapper">
            <label class="form-label">Empleado</label>
            <?= $this->Form->control('employee_id', array_merge(
                ['label' => false, 'options' => $viewModel->employees ?? [], 'empty' => '-- Seleccione --'],
                $canEdit('document_type')
                    ? ['class' => 'form-select select2-enable']
                    : ['class' => 'form-select select2-enable', 'disabled' => true]
            )) ?>
        </div>
        <div class="col-md-3 <?= ($viewModel->invoice->equivalent_holder_type ?? '') !== 'manual' ? 'd-none' : '' ?>" id="manual-doc-wrapper">
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

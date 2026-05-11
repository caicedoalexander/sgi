<?php
/**
 * Sección "Beneficiario" para Anticipos (variante del "general"
 * normal). Reemplaza el bloque por proveedor/empleado y oculta
 * el sub-form de Recibo de Caja.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var callable $canEdit
 */

use App\Constants\InvoiceConstants;

$currentBeneficiary = $viewModel->invoice->provider_id
    ? 'provider'
    : ($viewModel->invoice->employee_id ? 'employee' : '');
?>
<div class="mb-4 ">
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="text-uppercase fw-semibold flex-shrink-0"
              style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
            <i class="bi bi-person-badge me-1" aria-hidden="true"></i>Beneficiario
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
                ['label' => false, 'options' => $viewModel->providers, 'empty' => '-- Seleccione --'],
                $canEdit('provider_id')
                    ? ['class' => 'form-select select2-enable']
                    : ['class' => 'form-select select2-enable', 'disabled' => true],
            )) ?>
        </div>
        <div class="col-md-9 <?= $currentBeneficiary === 'employee' ? '' : 'd-none' ?>" id="employee-wrapper">
            <label class="form-label">Empleado</label>
            <?= $this->Form->control('employee_id', array_merge(
                ['label' => false, 'options' => $viewModel->employees ?? [], 'empty' => '-- Seleccione --'],
                $canEdit('employee_id')
                    ? ['class' => 'form-select select2-enable']
                    : ['class' => 'form-select select2-enable', 'disabled' => true],
            )) ?>
        </div>
    </div>
    <?= $this->Form->hidden('document_type', ['value' => InvoiceConstants::DOCTYPE_ANTICIPO]) ?>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 */

use App\Constants\InvoiceConstants;
use App\Service\InvoicePipelineService;

$this->assign('title', 'Anticipo #' . $invoice->id);

$pipelineBadge = [
    InvoiceConstants::STATUS_APROBACION        => 'bg-info text-dark',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'bg-primary',
    InvoiceConstants::STATUS_TESORERIA         => 'bg-warning text-dark',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bg-info',
    InvoiceConstants::STATUS_PAGADA            => 'bg-success',
];
$pipelineLabels = [
    InvoiceConstants::STATUS_APROBACION        => 'Aprobación',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'Contabilidad',
    InvoiceConstants::STATUS_TESORERIA         => 'Tesorería',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'Aut. Pago',
    InvoiceConstants::STATUS_PAGADA            => 'Pagada',
];

$beneficiary = $invoice->provider->name ?? ($invoice->employee->full_name ?? '—');
$beneficiaryType = $invoice->provider_id ? 'Proveedor' : ($invoice->employee_id ? 'Empleado' : '—');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Ver Anticipo</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['advances']['can_edit']) && $invoice->pipeline_status !== InvoiceConstants::STATUS_PAGADA): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1"></i>Editar',
            ['controller' => 'Invoices', 'action' => 'edit', $invoice->id],
            ['class' => 'btn btn-warning btn-sm', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-start justify-content-between gap-3" style="padding:1rem 1.25rem;">
        <div class="d-flex align-items-start gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:52px;height:52px;background:var(--primary-color);color:#fff;font-size:1.35rem;">
                <i class="bi bi-cash-coin"></i>
            </div>
            <div>
                <div style="font-size:1.25rem;font-weight:700;letter-spacing:-.03em;color:#111;line-height:1.15;font-family:monospace;">
                    Anticipo #<?= h($invoice->id) ?>
                </div>
                <div class="mt-1 d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge <?= $pipelineBadge[$invoice->pipeline_status] ?? 'bg-dark' ?>">
                        <?= $pipelineLabels[$invoice->pipeline_status] ?? h($invoice->pipeline_status) ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="text-end flex-shrink-0">
            <div style="font-size:.55rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#bbb;margin-bottom:.2rem;">Monto</div>
            <div style="font-size:1.55rem;font-weight:700;letter-spacing:-.04em;color:var(--primary-color);line-height:1;white-space:nowrap;">
                $ <?= $this->Number->format((float)$invoice->amount, ['places' => 2]) ?>
            </div>
        </div>
    </div>

    <!-- Pipeline -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('pipeline_progress', [
            'currentStatus' => $invoice->pipeline_status,
            'pipelineStatuses' => InvoiceConstants::PIPELINE_STATUSES,
            'pipelineLabels' => InvoicePipelineService::STATUS_LABELS,
            'isRejected' => false,
            'paymentStatus' => $invoice->payment_status ?? null,
        ]) ?>
    </div>

    <!-- Info -->
    <div class="row g-0">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Beneficiario</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo</span>
                <span class="sgi-data-value"><?= h($beneficiaryType) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Nombre</span>
                <span class="sgi-data-value"><?= h($beneficiary) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Centro de Operación</span>
                <span class="sgi-data-value"><?= h($invoice->operation_center->name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo de Gasto</span>
                <span class="sgi-data-value"><?= h($invoice->expense_type->name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Centro de Costos</span>
                <span class="sgi-data-value"><?= h($invoice->cost_center->name ?? '—') ?></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="sgi-section-title">Detalle</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha de Emisión</span>
                <span class="sgi-data-value"><?= $invoice->issue_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Registrado por</span>
                <span class="sgi-data-value"><?= $invoice->hasValue('registered_by_user') ? h($invoice->registered_by_user->full_name ?? '—') : '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha de Registro</span>
                <span class="sgi-data-value"><?= $invoice->created?->format('d/m/Y H:i') ?? '—' ?></span>
            </div>
            <div class="sgi-data-row align-items-start">
                <span class="sgi-data-label">Concepto</span>
                <span class="sgi-data-value"><?= $invoice->detail ? nl2br(h($invoice->detail)) : '—' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info d-flex align-items-center gap-2">
    <i class="bi bi-info-circle-fill"></i>
    <span>La legalización iniciará automáticamente cuando este anticipo llegue al estado <strong>Pagada</strong>.</span>
</div>

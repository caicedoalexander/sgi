<?php
/**
 * Encabezado de página de Invoices/edit: breadcrumb + título + ID +
 * pill de estado + acciones (Volver / Ver). Alineado al spec del
 * Sistema de Diseño (ver .claude/rules/design.md).
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var bool $isAdvance
 */

use App\Constants\InvoiceConstants;

$currentStatus = $viewModel->currentStatus;
$statusLabel = $viewModel->pipelineLabels[$currentStatus] ?? 'Desconocido';

// Pill kind del estado del pipeline (soft variants) — espejado de view.php.
$statusPills = [
    InvoiceConstants::STATUS_APROBACION        => 'pill-warning-soft',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'pill-secondary-soft',
    InvoiceConstants::STATUS_TESORERIA         => 'pill-info-soft',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
    InvoiceConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
    InvoiceConstants::STATUS_PAGADA            => 'pill-primary-soft',
    InvoiceConstants::STATUS_LEGALIZADA        => 'pill-primary-soft',
];
$statusPill = $statusPills[$currentStatus] ?? 'pill-muted';

$indexUrl = $isAdvance
    ? ['controller' => 'Advances', 'action' => 'index']
    : ['action' => 'index'];
$viewUrl = $isAdvance
    ? ['controller' => 'Advances', 'action' => 'view', $viewModel->invoice->id]
    : ['action' => 'view', $viewModel->invoice->id];
$listLabel = $isAdvance ? 'Todos los Anticipos' : 'Todas las Facturas';
$titleLabel = $isAdvance ? 'Editar Anticipo' : 'Editar Factura';
$idLabel = $viewModel->invoice->invoice_number ?? ('#' . $viewModel->invoice->id);
?>
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link($listLabel, $indexUrl) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:10px;"></i>
            <?= $this->Html->link(h($idLabel), $viewUrl) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:10px;"></i>
            <span class="current">Editar</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title"><?= h($titleLabel) ?></span>
            <span class="sgi-edit-id-chip"><?= h($idLabel) ?></span>
            <?php if ($viewModel->isRejected): ?>
                <span class="pill pill-danger-soft">Rechazada</span>
            <?php elseif ($viewModel->isApproved): ?>
                <span class="pill pill-primary-soft">
                    <i class="bi bi-check2" aria-hidden="true" style="font-size:9px;"></i>Aprobada
                </span>
            <?php else: ?>
                <span class="pill <?= $statusPill ?>"><?= h($statusLabel) ?></span>
            <?php endif; ?>
            <?php if ($currentStatus === InvoiceConstants::STATUS_TESORERIA
                      && $viewModel->invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL): ?>
                <span class="pill pill-warning-soft">Pago Parcial</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            $indexUrl,
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye" aria-hidden="true"></i>Ver',
            $viewUrl,
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
    </div>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var string $roleName
 * @var bool $isRejected
 * @var string[] $pipelineStatuses
 * @var string[] $pipelineLabels
 */
$this->assign('title', 'Factura ' . ($invoice->invoice_number ?? '#' . $invoice->id));

$pipelineBadgeMap = [
    'revision'      => ['Revisión',      'bg-secondary'],
    'area_approved' => ['Área Aprobada', 'bg-info text-dark'],
    'accrued'       => ['Causada',       'bg-primary'],
    'treasury'      => ['Tesorería',     'bg-warning text-dark'],
    'paid'          => ['Pagada',        'bg-success'],
];
$ps = $pipelineBadgeMap[$invoice->pipeline_status] ?? ['Desconocido', 'bg-dark'];

$approvalClass = match($invoice->area_approval ?? '') {
    'Aprobada'  => 'bg-success',
    'Rechazada' => 'bg-danger',
    default     => 'bg-secondary',
};
$dianClass = match($invoice->dian_validation ?? '') {
    'Aprobada'  => 'bg-success',
    'Rechazado' => 'bg-danger',
    default     => 'bg-secondary',
};
?>

<!-- Encabezado de página -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Ver Factura</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1"></i>Editar',
            ['action' => 'edit', $invoice->id],
            ['class' => 'btn btn-warning btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Tarjeta principal del documento -->
<div class="card card-primary mb-4">

    <!-- Cabecera: número + monto -->
    <div class="card-header d-flex align-items-start justify-content-between gap-3"
         style="padding:1rem 1.25rem;">
        <div class="d-flex align-items-start gap-3">
            <!-- Ícono -->
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:52px;height:52px;background:var(--primary-color);color:#fff;font-size:1.35rem;">
                <i class="bi bi-receipt"></i>
            </div>
            <!-- Número, tipo y badges -->
            <div>
                <div style="font-size:1.25rem;font-weight:700;letter-spacing:-.03em;color:#111;line-height:1.15;font-family:monospace;">
                    <?= h($invoice->invoice_number ?? ('# ' . $invoice->id)) ?>
                </div>
                <div class="mt-1 d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-secondary"><?= h($invoice->document_type) ?></span>
                    <span class="badge <?= $ps[1] ?>"><?= $ps[0] ?></span>
                    <?php if ($isRejected): ?>
                        <span class="badge bg-danger">Rechazada</span>
                    <?php endif; ?>
                    <?php if ($invoice->pipeline_status === 'treasury' && $invoice->payment_status === 'Pago Parcial'): ?>
                        <span class="badge bg-warning text-dark">Pago Parcial</span>
                    <?php endif; ?>
                </div>
                <div class="mt-1" style="font-size:.8rem;color:#777;font-weight:500;">
                    <?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '<span class="text-muted">—</span>' ?>
                    <?php if ($invoice->hasValue('operation_center')): ?>
                        <span style="color:#ccc;margin:0 .35rem;">·</span><?= h($invoice->operation_center->name) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Monto destacado -->
        <div class="text-end flex-shrink-0">
            <div style="font-size:.55rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#bbb;margin-bottom:.2rem;">Valor</div>
            <div style="font-size:1.55rem;font-weight:700;letter-spacing:-.04em;color:var(--primary-color);line-height:1;white-space:nowrap;">
                $ <?= $this->Number->format($invoice->amount, ['places' => 2]) ?>
            </div>
        </div>
    </div>

    <!-- Pipeline progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('pipeline_progress', [
            'currentStatus'    => $invoice->pipeline_status,
            'pipelineStatuses' => $pipelineStatuses,
            'pipelineLabels'   => $pipelineLabels,
            'isRejected'       => $isRejected,
            'paymentStatus'    => $invoice->payment_status,
        ]) ?>
    </div>

    <!-- Sección: Documento + Clasificación (dos columnas) -->
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Documento</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Registro</span>
                <span class="sgi-data-value"><?= $this->formatDateEs($invoice->registration_date) ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Emisión</span>
                <span class="sgi-data-value"><?= $this->formatDateEs($invoice->issue_date) ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Vencimiento</span>
                <span class="sgi-data-value"><?= $this->formatDateEs($invoice->due_date) ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Orden de Compra</span>
                <span class="sgi-data-value"><?= h($invoice->purchase_order) ?: '—' ?></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="sgi-section-title">Clasificación</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Proveedor</span>
                <span class="sgi-data-value"><?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo de Gasto</span>
                <span class="sgi-data-value"><?= $invoice->hasValue('expense_type') ? h($invoice->expense_type->name) : '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Centro de Costos</span>
                <span class="sgi-data-value"><?= $invoice->hasValue('cost_center') ? h($invoice->cost_center->name) : '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Centro Operación</span>
                <span class="sgi-data-value"><?= $invoice->hasValue('operation_center') ? h($invoice->operation_center->name) : '—' ?></span>
            </div>
        </div>
    </div>

    <!-- Sección: Detalle -->
    <?php if ($invoice->detail): ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-section-title">Detalle</div>
        <div style="padding:.25rem 1.25rem .875rem;font-size:.875rem;color:#333;line-height:1.65;">
            <?= nl2br(h($invoice->detail)) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sección: Observaciones -->
    <?php if ($invoice->observations): ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-section-title">Observaciones</div>
        <div style="padding:.25rem 1.25rem .875rem;font-size:.875rem;color:#555;line-height:1.65;font-style:italic;">
            <?= nl2br(h($invoice->observations)) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sección: Revisión | Contabilidad | Tesorería -->
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <!-- Revisión -->
        <div class="col-md-4" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Revisión</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Confirmado por</span>
                <span class="sgi-data-value">
                    <?= $invoice->hasValue('confirmed_by_user') ? h($invoice->confirmed_by_user->full_name) : '—' ?>
                </span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Aprobador</span>
                <span class="sgi-data-value">
                    <?= $invoice->hasValue('approver_user') ? h($invoice->approver_user->full_name) : '—' ?>
                </span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Aprobación Área</span>
                <span class="sgi-data-value">
                    <span class="badge <?= $approvalClass ?>"><?= h($invoice->area_approval ?? 'Pendiente') ?></span>
                </span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Aprobación</span>
                <span class="sgi-data-value"><?= $this->formatDateEs($invoice->area_approval_date) ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Validación DIAN</span>
                <span class="sgi-data-value">
                    <span class="badge <?= $dianClass ?>"><?= h($invoice->dian_validation ?? 'Pendiente') ?></span>
                </span>
            </div>
        </div>
        <!-- Contabilidad -->
        <div class="col-md-4" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Contabilidad</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Causada</span>
                <span class="sgi-data-value">
                    <?= $invoice->accrued
                        ? '<span class="badge bg-success">Sí</span>'
                        : '<span class="badge bg-secondary">No</span>' ?>
                </span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Causación</span>
                <span class="sgi-data-value"><?= $this->formatDateEs($invoice->accrual_date) ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Lista para Pago</span>
                <span class="sgi-data-value"><?= h($invoice->ready_for_payment) ?: '—' ?></span>
            </div>
        </div>
        <!-- Tesorería -->
        <div class="col-md-4">
            <div class="sgi-section-title">Tesorería</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado Pago</span>
                <span class="sgi-data-value">
                    <?php if ($invoice->payment_status === 'Pago Parcial'): ?>
                        <span class="badge bg-warning text-dark"><?= h($invoice->payment_status) ?></span>
                    <?php elseif ($invoice->payment_status === 'Pago total'): ?>
                        <span class="badge bg-success"><?= h($invoice->payment_status) ?></span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Pago</span>
                <span class="sgi-data-value"><?= $this->formatDateEs($invoice->payment_date) ?: '—' ?></span>
            </div>
        </div>
    </div>

    <!-- Barra de registro -->
    <div class="sgi-contact-bar">
        <?php if ($invoice->hasValue('registered_by_user')): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-person"></i>
            <span>Registrado por <?= h($invoice->registered_by_user->full_name) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($invoice->created): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-calendar3"></i>
            <span>Creado: <?= $this->formatDateEs($invoice->created) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($invoice->modified): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-pencil-square"></i>
            <span>Modificado: <?= $this->formatDateEs($invoice->modified) ?></span>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Historial de cambios -->
<?php if (!empty($invoice->invoice_histories)): ?>
<div class="card">
    <div class="card-header">Historial de Cambios</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Campo</th>
                    <th>Valor Anterior</th>
                    <th>Valor Nuevo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoice->invoice_histories as $history): ?>
                <tr>
                    <td><?= $this->formatDateEs($history->created) ?></td>
                    <td><?= $history->hasValue('user') ? h($history->user->full_name) : '' ?></td>
                    <td><code><?= h($history->field_changed) ?></code></td>
                    <td class="text-muted"><?= h($history->old_value) ?: '—' ?></td>
                    <td class="fw-semibold"><?= h($history->new_value) ?: '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

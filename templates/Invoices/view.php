<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var string $roleName
 * @var bool $isRejected
 * @var string[] $pipelineStatuses
 * @var string[] $pipelineLabels
 * @var string[] $fieldLabels
 */

use App\Constants\InvoiceConstants;
use App\Service\InvoicePipelineService;
use App\View\Presentation\InvoicePresentation;
use App\View\Presentation\SharedPresentation;

$this->assign('title', 'Factura ' . ($invoice->invoice_number ?? '#' . $invoice->id));

$ps = [
    InvoiceConstants::STATUS_LABELS[$invoice->pipeline_status] ?? 'Desconocido',
    InvoicePresentation::STATUS_BADGES[$invoice->pipeline_status] ?? 'bg-dark',
];

$approvalClass = match($invoice->area_approval ?? '') {
    InvoiceConstants::APPROVAL_APPROVED  => 'bg-success',
    InvoiceConstants::APPROVAL_REJECTED => 'bg-danger',
    default     => 'bg-secondary',
};
$dianClass = match($invoice->dian_validation ?? '') {
    InvoiceConstants::DIAN_APPROVED  => 'bg-success',
    InvoiceConstants::DIAN_REJECTED => 'bg-danger',
    default     => 'bg-secondary',
};
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
    <span class="sgi-page-title">Ver Factura</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?php if ($canShowEdit): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1"></i>Editar',
            ['action' => 'edit', $invoice->id],
            ['class' => 'btn btn-warning btn-sm', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($showPettyCashLock)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-lock-fill fs-5"></i>
    <div>
        Factura bloqueada: pertenece al registro de Caja Menor
        <strong><?= $this->Html->link(
            h($invoice->petty_cash_record->code ?? '#' . $invoice->petty_cash_record_id),
            ['controller' => 'PettyCashRecords', 'action' => 'view', $invoice->petty_cash_record_id],
            ['class' => 'alert-link']
        ) ?></strong>. Los cambios se gestionan desde allí.
    </div>
</div>
<?php endif; ?>


<?php if (!empty($showSchedulingLock)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-lock-fill fs-5"></i>
    <div>
        Factura bloqueada: tiene pagos aplicados desde una <strong>programación ya pagada</strong>.
    </div>
</div>
<?php endif; ?>

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
                    <?php if (!empty($isApproved)): ?>
                        <span class="badge bg-success">Aprobada</span>
                    <?php endif; ?>
                    <?php if ($invoice->pipeline_status === InvoiceConstants::STATUS_TESORERIA && $invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL): ?>
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
            'isApproved'       => $isApproved ?? false,
            'paymentStatus'    => $invoice->payment_status,
        ]) ?>
    </div>

    <!-- Sección: Documento + Clasificación (dos columnas) -->
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Documento</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Registro</span>
                <span class="sgi-data-value"><?= $invoice->registration_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Emisión</span>
                <span class="sgi-data-value"><?= $invoice->issue_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Vencimiento</span>
                <span class="sgi-data-value"><?= $invoice->due_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Orden de Compra</span>
                <span class="sgi-data-value"><?= h($invoice->purchase_order) ?: '—' ?></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="sgi-section-title">Clasificación</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Titular</span>
                <span class="sgi-data-value">
                    <?php $isReciboDeCaja = ($invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA; ?>
                    <?php if ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'employee'): ?>
                        <?= $invoice->hasValue('employee') ? h($invoice->employee->full_name) : '—' ?>
                        <span class="text-muted small">(Empleado)</span>
                    <?php elseif ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === 'manual'): ?>
                        <?= h($invoice->manual_document_number ?? '—') ?>
                        <span class="text-muted small">(Cédula Manual)</span>
                    <?php else: ?>
                        <?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '—' ?>
                    <?php endif; ?>
                </span>
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

    <!-- Sección: Observaciones (chat) -->
    <?php if (!empty($invoice->invoice_observations)): ?>
    <?php $statusLabels = InvoiceConstants::STATUS_LABELS; ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-section-title">Observaciones</div>
        <div style="padding:.5rem 1.25rem .875rem;max-height:400px;overflow-y:auto;">
            <?php foreach ($invoice->invoice_observations as $obs): ?>
            <?php
                $obsType = $obs->type ?? null;
                $isRegression = $obsType === \App\Constants\InvoiceConstants::OBSERVATION_TYPE_REGRESSION;
                $isExternalApproval = $obsType === \App\Constants\InvoiceConstants::OBSERVATION_TYPE_EXTERNAL_APPROVAL;
                $meta = $obs->metadata ?? [];
                if (is_string($meta)) {
                    $decoded = json_decode($meta, true);
                    $meta = is_array($decoded) ? $decoded : [];
                }
                $fromLbl = $statusLabels[$meta['from_status'] ?? ''] ?? null;
                $toLbl = $statusLabels[$meta['to_status'] ?? ''] ?? null;
                $extAction = $meta['action'] ?? null;
                $extActionLabel = $extAction === 'approve' ? 'Aprobada' : ($extAction === 'reject' ? 'Rechazada' : null);
                if ($isExternalApproval) {
                    $avatarBg = $extAction === 'reject' ? '#dc3545' : '#469D61';
                } elseif ($isRegression) {
                    $avatarBg = '#CD6A15';
                } else {
                    $avatarBg = 'var(--primary-color)';
                }
            ?>
            <div class="d-flex align-items-start gap-2 mb-3">
                <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:32px;height:32px;background:<?= $avatarBg ?>;color:#fff;font-size:.7rem;font-weight:700;">
                    <?php
                    $names = explode(' ', $obs->user->full_name ?? '');
                    echo strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[1] ?? '', 0, 1));
                    ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span style="font-size:.8rem;font-weight:600;color:#222;">
                            <?= h($obs->user->full_name ?? '') ?>
                        </span>
                        <?php if ($isRegression): ?>
                            <span class="badge bg-warning text-dark" style="font-size:.65rem;">Regresión</span>
                        <?php elseif ($isExternalApproval): ?>
                            <span class="badge <?= $extAction === 'reject' ? 'bg-danger' : 'bg-success' ?>" style="font-size:.65rem;">
                                Aprobación Externa<?= $extActionLabel ? ': ' . h($extActionLabel) : '' ?>
                            </span>
                        <?php endif; ?>
                        <span style="font-size:.7rem;color:#aaa;">
                            <?= $obs->created ? $obs->created->format('d/m/Y H:i') : '' ?>
                        </span>
                    </div>
                    <?php if ($isRegression && $fromLbl && $toLbl): ?>
                        <div style="font-size:.74rem;color:#666;margin-top:.1rem;">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                            <?= h($fromLbl) ?> &rarr; <?= h($toLbl) ?>
                        </div>
                    <?php endif; ?>
                    <div style="font-size:.84rem;color:#444;line-height:1.5;margin-top:.15rem;">
                        <?= nl2br(h($obs->message)) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sección: Revisión | Contabilidad | Tesorería -->
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <!-- Revisión -->
        <div class="col-md-4" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Revisión</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Aprobador</span>
                <span class="sgi-data-value">
                    <?= $invoice->hasValue('approver_user') ? h($invoice->approver_user->full_name) : '—' ?>
                </span>
            </div>
            <?php
            $approvedNames = [];
            foreach ($invoice->invoice_approvals ?? [] as $a) {
                if ($a->status === InvoiceConstants::APPROVER_STATUS_APPROVED && $a->hasValue('user')) {
                    $approvedNames[] = $a->user->full_name ?? $a->user->username ?? ('Usuario #' . $a->user_id);
                }
            }
            ?>
            <?php if (!empty($approvedNames)): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Aprobado Por</span>
                <span class="sgi-data-value"><?= h(implode(', ', $approvedNames)) ?></span>
            </div>
            <?php endif; ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Aprobación Área</span>
                <span class="sgi-data-value">
                    <span class="badge <?= $approvalClass ?>"><?= h($invoice->area_approval ?? 'Pendiente') ?></span>
                </span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Aprobación</span>
                <span class="sgi-data-value"><?= $invoice->area_approval_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Validación DIAN</span>
                <span class="sgi-data-value">
                    <span class="badge <?= $dianClass ?>"><?= h($invoice->dian_validation ?? InvoiceConstants::DIAN_PENDING) ?></span>
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
                <span class="sgi-data-value"><?= $invoice->accrual_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Lista para Pago</span>
                <span class="sgi-data-value">
                    <?php if (!empty($invoice->ready_for_payment)): ?>
                        <span class="badge <?= SharedPresentation::READY_FOR_PAYMENT_BADGES[$invoice->ready_for_payment] ?? 'bg-secondary' ?>"><?= h($invoice->ready_for_payment) ?></span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <!-- Tesorería -->
        <div class="col-md-4">
            <div class="sgi-section-title">Tesorería</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado Pago</span>
                <span class="sgi-data-value">
                    <?php if ($invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL): ?>
                        <span class="badge bg-warning text-dark"><?= h($invoice->payment_status) ?></span>
                    <?php elseif ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL): ?>
                        <span class="badge bg-success"><?= h($invoice->payment_status) ?></span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Pago Total</span>
                <span class="sgi-data-value"><?= $invoice->full_payment_date?->format('d/m/Y') ?? '—' ?></span>
            </div>
        </div>
    </div>

    <!-- Pagos Registrados -->
    <div class="row g-3 mb-3">
        <?php if (!empty($invoice->invoice_payments)): ?>
        <div class="col-md-12">
            <div class="sgi-section-title">Pagos Registrados</div>
            <div style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Entidad Bancaria</th>
                            <th>Monto</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th>Registrado por</th>
                            <th>Origen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoice->invoice_payments as $payment): ?>
                        <tr>
                            <td><?= h($payment->banking_entity->name ?? '—') ?></td>
                            <td>$ <?= number_format((float)$payment->amount, 0, ',', '.') ?></td>
                            <td><?= $payment->payment_date?->format('d/m/Y') ?? '—' ?></td>
                            <td>
                                <?php $pSt = $payment->status ?? ($payment->authorized ? 'authorized' : 'pending'); ?>
                                <?php if ($pSt === 'authorized'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Autorizado</span>
                                    <?php if ($payment->authorized_by_user): ?>
                                    <br><small class="text-muted"><?= h($payment->authorized_by_user->full_name ?? '') ?> - <?= $payment->authorized_date?->format('d/m/Y') ?? '' ?></small>
                                    <?php endif; ?>
                                <?php elseif ($pSt === 'rejected'): ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Rechazado</span>
                                    <?php if (!empty($payment->rejection_reason)): ?>
                                    <br><small class="text-muted"><?= h($payment->rejection_reason) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pendiente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= h($payment->created_by_user->full_name ?? $payment->created_by_user->username ?? '—') ?>
                            </td>
                            <td>
                                <?php if (!empty($payment->payment_scheduling_id)): ?>
                                    <?= $this->Html->link(
                                        '<i class="bi bi-calendar-check me-1"></i>Programación ' . h($payment->payment_scheduling->code ?? '#' . $payment->payment_scheduling_id),
                                        ['controller' => 'PaymentSchedulings', 'action' => 'view', $payment->payment_scheduling_id],
                                        ['class' => 'badge bg-light text-dark text-decoration-none border', 'escape' => false]
                                    ) ?>
                                <?php elseif (!empty($payment->petty_cash_record_id)): ?>
                                    <?= $this->Html->link(
                                        '<i class="bi bi-wallet2 me-1"></i>Caja Menor ' . h($payment->petty_cash_record->code ?? '#' . $payment->petty_cash_record_id),
                                        ['controller' => 'PettyCashRecords', 'action' => 'view', $payment->petty_cash_record_id],
                                        ['class' => 'badge bg-light text-dark text-decoration-none border', 'escape' => false]
                                    ) ?>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:.75rem;">Individual</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th>Total Pagado</th>
                            <th colspan="5">$ <?= number_format(array_sum(array_map(fn($p) => (float)$p->amount, $invoice->invoice_payments)), 0, ',', '.') ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

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
            <span>Creado: <?= $invoice->created?->format('d/m/Y') ?? '' ?></span>
        </div>
        <?php endif; ?>
        <?php if ($invoice->modified): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-pencil-square"></i>
            <span>Modificado: <?= $invoice->modified?->format('d/m/Y') ?? '' ?></span>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Soportes Documentales (solo lectura) -->
<?php
$documentsByStatus = $documentsByStatus ?? [];
$statusLabels = InvoiceConstants::STATUS_LABELS;
$totalDocs = array_sum(array_map('count', $documentsByStatus));
?>
<div class="card card-primary mb-4">
    <div class="card-header">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip"></i>
            Soportes
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
    </div>

    <?php if (empty($documentsByStatus)): ?>
        <div class="p-3 text-center text-muted" style="font-size:.875rem">
            <i class="bi bi-file-earmark-x me-1"></i>Sin soportes adjuntos
        </div>
    <?php else: ?>
        <div class="p-3">
            <div class="row row-cols-1 row-cols-md-3 g-3">
                <?php foreach ($documentsByStatus as $status => $docs): ?>
                    <?php foreach ($docs as $doc): ?>
                    <div class="col">
                        <div style="border:1px solid var(--border-color);height:100%;display:flex;flex-direction:column;">
                            <!-- Card header: icono + nombre -->
                            <div style="padding:.6rem .875rem;border-bottom:1px solid var(--border-color);background:#fafafa;display:flex;align-items:center;gap:.5rem;min-width:0;">
                                <i class="bi <?= h($this->DocumentIcon->iconClass($doc->mime_type)) ?> flex-shrink-0"
                                   style="color:<?= h($this->DocumentIcon->iconColor($doc->mime_type)) ?>;font-size:1.1rem;"></i>
                                <div style="min-width:0;flex:1;overflow:hidden;">
                                    <span style="font-size:.78rem;font-weight:600;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;" title="<?= h($doc->document_type ?: $doc->file_name) ?>">
                                        <?= h($doc->document_type ?: $doc->file_name) ?>
                                    </span>
                                    <?php if ($doc->document_type): ?>
                                    <span style="font-size:.7rem;color:#888;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($doc->file_name) ?>">
                                        <?= h($doc->file_name) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Card body: badge estado + usuario + fecha + tamaño -->
                            <div style="padding:.6rem .875rem;flex:1;font-size:.78rem;color:#555;display:flex;flex-direction:column;gap:.3rem;">
                                <div>
                                    <span class="badge <?= InvoicePresentation::STATUS_BADGES[$status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
                                        <?= $statusLabels[$status] ?? $status ?>
                                    </span>
                                </div>
                                <div style="display:flex;align-items:center;gap:.35rem;color:#666;">
                                    <i class="bi bi-person" style="font-size:.8rem;"></i>
                                    <span><?= $doc->has('uploaded_by_user') ? h($doc->uploaded_by_user->full_name) : '—' ?></span>
                                </div>
                                <div style="display:flex;align-items:center;gap:.35rem;color:#888;">
                                    <i class="bi bi-clock" style="font-size:.75rem;"></i>
                                    <span><?= $doc->created?->format('d/m/Y H:i') ?></span>
                                </div>
                                <?php if ($doc->file_size): ?>
                                <div style="color:#aaa;font-size:.72rem;"><?= $this->Number->toReadableSize($doc->file_size) ?></div>
                                <?php endif; ?>
                            </div>
                            <!-- Card footer: botón abrir -->
                            <div style="padding:.5rem .875rem;border-top:1px solid var(--border-color);text-align:right;">
                                <?= $this->Html->link(
                                    '<i class="bi bi-box-arrow-up-right me-1"></i>Abrir',
                                    '/' . $doc->file_path,
                                    ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank']
                                ) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
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
                    <td><?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?></td>
                    <td><?= $history->hasValue('user') ? h($history->user->full_name) : '' ?></td>
                    <td><?= h($fieldLabels[$history->field_changed] ?? $history->field_changed) ?></td>
                    <td class="text-muted"><?= h($history->old_value) ?: '—' ?></td>
                    <td class="fw-semibold"><?= h($history->new_value) ?: '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

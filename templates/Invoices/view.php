<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var string $roleName
 * @var bool $isRejected
 * @var string[] $pipelineStatuses
 * @var string[] $pipelineLabels
 */
$this->assign('title', 'Factura #' . $invoice->id);

$pipelineBadgeMap = [
    'revision'     => ['Revisión', 'bg-secondary'],
    'area_approved' => ['Área Aprobada', 'bg-info text-dark'],
    'accrued'      => ['Causada', 'bg-primary'],
    'treasury'     => ['Tesorería', 'bg-warning text-dark'],
    'paid'         => ['Pagada', 'bg-success'],
];
$ps = $pipelineBadgeMap[$invoice->pipeline_status] ?? ['Desconocido', 'bg-dark'];
?>
<div class="mb-4 d-flex gap-2 align-items-center flex-wrap">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
    <?= $this->Html->link('<i class="bi bi-pencil me-1"></i>Editar', ['action' => 'edit', $invoice->id], ['class' => 'btn btn-warning btn-sm', 'escape' => false]) ?>
    <span class="ms-2">
        <span class="badge <?= $ps[1] ?> fs-6"><?= $ps[0] ?></span>
        <?php if ($invoice->pipeline_status === 'treasury' && $invoice->payment_status === 'Pago Parcial'): ?>
            <span class="badge bg-warning text-dark fs-6">Pago Parcial</span>
        <?php endif; ?>
    </span>
</div>

<!-- Pipeline visual mejorado -->
<?= $this->element('pipeline_progress', [
    'currentStatus'    => $invoice->pipeline_status,
    'pipelineStatuses' => $pipelineStatuses,
    'pipelineLabels'   => $pipelineLabels,
    'isRejected'       => $isRejected,
    'paymentStatus'    => $invoice->payment_status,
]) ?>

<div class="row">
    <!-- Datos Generales -->
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="mb-0">Datos del Documento</h5></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">No. Factura</dt>
                            <dd class="col-sm-7"><strong><?= h($invoice->invoice_number ?? '—') ?></strong></dd>
                            <dt class="col-sm-5">Tipo Documento</dt>
                            <dd class="col-sm-7"><?= h($invoice->document_type) ?></dd>
                            <dt class="col-sm-5">Proveedor</dt>
                            <dd class="col-sm-7"><?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '' ?></dd>
                            <dt class="col-sm-5">Orden de Compra</dt>
                            <dd class="col-sm-7"><?= h($invoice->purchase_order) ?: '—' ?></dd>
                            <dt class="col-sm-5">Centro Operación</dt>
                            <dd class="col-sm-7"><?= $invoice->hasValue('operation_center') ? h($invoice->operation_center->name) : '' ?></dd>
                        </dl>
                    </div>
                    <div class="col-md-6">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Fecha Registro</dt>
                            <dd class="col-sm-7"><?= $this->formatDateEs($invoice->registration_date) ?></dd>
                            <dt class="col-sm-5">Fecha Emisión</dt>
                            <dd class="col-sm-7"><?= $this->formatDateEs($invoice->issue_date) ?></dd>
                            <dt class="col-sm-5">Fecha Vencimiento</dt>
                            <dd class="col-sm-7"><?= $this->formatDateEs($invoice->due_date) ?></dd>
                            <dt class="col-sm-5">Valor</dt>
                            <dd class="col-sm-7 fw-bold text-success">$ <?= $this->Number->format($invoice->amount, ['places' => 2]) ?></dd>
                        </dl>
                    </div>
                </div>
                <hr>
                <dl class="row mb-0">
                    <dt class="col-sm-3">Tipo de Gasto</dt>
                    <dd class="col-sm-3"><?= $invoice->hasValue('expense_type') ? h($invoice->expense_type->name) : '' ?></dd>
                    <dt class="col-sm-3">Centro de Costos</dt>
                    <dd class="col-sm-3"><?= $invoice->hasValue('cost_center') ? h($invoice->cost_center->name) : '' ?></dd>
                </dl>
                <hr>
                <dt>Detalle</dt>
                <dd class="mt-1"><?= h($invoice->detail) ?></dd>
            </div>
        </div>

        <!-- Observaciones -->
        <?php if ($invoice->observations): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="mb-0">Observaciones</h5></div>
            <div class="card-body"><?= h($invoice->observations) ?></div>
        </div>
        <?php endif; ?>

        <!-- Historial -->
        <?php if (!empty($invoice->invoice_histories)): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="mb-0">Historial de Cambios</h5></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
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
    </div>

    <!-- Panel Lateral -->
    <div class="col-lg-4">
        <!-- Revisión -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-secondary text-white"><h6 class="mb-0">Revisión</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">Confirmado por</dt>
                    <dd class="col-6"><?= $invoice->hasValue('confirmed_by_user') ? h($invoice->confirmed_by_user->full_name) : '<span class="text-muted">—</span>' ?></dd>
                    <dt class="col-6">Aprobador</dt>
                    <dd class="col-6"><?= $invoice->hasValue('approver_user') ? h($invoice->approver_user->full_name) : '<span class="text-muted">—</span>' ?></dd>
                    <dt class="col-6">Aprobación Área</dt>
                    <dd class="col-6">
                        <?php
                        $approvalClass = match($invoice->area_approval) {
                            'Aprobada' => 'bg-success',
                            'Rechazada' => 'bg-danger',
                            default => 'bg-secondary',
                        };
                        ?>
                        <span class="badge <?= $approvalClass ?>"><?= h($invoice->area_approval ?? 'Pendiente') ?></span>
                    </dd>
                    <dt class="col-6">Fecha Aprobación</dt>
                    <dd class="col-6"><?= $this->formatDateEs($invoice->area_approval_date) ?></dd>
                    <dt class="col-6">Validación DIAN</dt>
                    <dd class="col-6">
                        <?php
                        $dianClass = match($invoice->dian_validation) {
                            'Aprobada' => 'bg-success',
                            'Rechazado' => 'bg-danger',
                            default => 'bg-secondary',
                        };
                        ?>
                        <span class="badge <?= $dianClass ?>"><?= h($invoice->dian_validation ?? 'Pendiente') ?></span>
                    </dd>
                </dl>
            </div>
        </div>

        <!-- Contabilidad -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white"><h6 class="mb-0">Contabilidad</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">Causada</dt>
                    <dd class="col-6"><?= $invoice->accrued ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-secondary">No</span>' ?></dd>
                    <dt class="col-6">Fecha Causación</dt>
                    <dd class="col-6"><?= $this->formatDateEs($invoice->accrual_date) ?></dd>
                    <dt class="col-6">Lista para Pago</dt>
                    <dd class="col-6"><?= h($invoice->ready_for_payment) ?: '—' ?></dd>
                </dl>
            </div>
        </div>

        <!-- Tesorería -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-warning text-dark"><h6 class="mb-0">Tesorería</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">Estado Pago</dt>
                    <dd class="col-6">
                        <?php if ($invoice->payment_status === 'Pago Parcial'): ?>
                            <span class="badge bg-warning text-dark"><?= h($invoice->payment_status) ?></span>
                        <?php elseif ($invoice->payment_status === 'Pago total'): ?>
                            <span class="badge bg-success"><?= h($invoice->payment_status) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-6">Fecha Pago</dt>
                    <dd class="col-6"><?= $this->formatDateEs($invoice->payment_date) ?></dd>
                </dl>
            </div>
        </div>

        <!-- Registro -->
        <div class="card shadow-sm">
            <div class="card-header"><h6 class="mb-0">Registro</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">Registrado por</dt>
                    <dd class="col-6"><?= $invoice->hasValue('registered_by_user') ? h($invoice->registered_by_user->full_name) : '' ?></dd>
                    <dt class="col-6">Creado</dt>
                    <dd class="col-6"><?= $this->formatDateEs($invoice->created) ?></dd>
                    <dt class="col-6">Modificado</dt>
                    <dd class="col-6"><?= $this->formatDateEs($invoice->modified) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

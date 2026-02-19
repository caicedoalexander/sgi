<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 */
$this->assign('title', 'Factura #' . $invoice->id);

$pipelineLabels = [
    'revision' => ['Revisión', 'bg-secondary'],
    'area_approved' => ['Área Aprobada', 'bg-info'],
    'accrued' => ['Causada', 'bg-primary'],
    'treasury' => ['Tesorería', 'bg-warning text-dark'],
    'paid' => ['Pagada', 'bg-success'],
];
$ps = $pipelineLabels[$invoice->pipeline_status] ?? ['Desconocido', 'bg-dark'];
?>
<div class="mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
    <?= $this->Html->link('<i class="bi bi-pencil me-1"></i>Editar', ['action' => 'edit', $invoice->id], ['class' => 'btn btn-warning btn-sm', 'escape' => false]) ?>
</div>

<!-- Pipeline Status Bar -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
            <?php
            $steps = ['revision' => 'Revisión', 'area_approved' => 'Área Aprobada', 'accrued' => 'Causada', 'treasury' => 'Tesorería', 'paid' => 'Pagada'];
            $stepKeys = array_keys($steps);
            $currentIndex = array_search($invoice->pipeline_status, $stepKeys);
            foreach ($steps as $key => $label):
                $index = array_search($key, $stepKeys);
                $isActive = $index <= $currentIndex;
                $isCurrent = $key === $invoice->pipeline_status;
            ?>
            <div class="text-center flex-fill">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center <?= $isActive ? 'bg-primary text-white' : 'bg-light text-muted border' ?>" style="width: 36px; height: 36px;">
                    <?= $isActive ? '<i class="bi bi-check-lg"></i>' : ($index + 1) ?>
                </div>
                <div class="small mt-1 <?= $isCurrent ? 'fw-bold' : 'text-muted' ?>"><?= $label ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

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
                            <dd class="col-sm-7"><?= $invoice->registration_date?->format('d/m/Y') ?></dd>
                            <dt class="col-sm-5">Fecha Emisión</dt>
                            <dd class="col-sm-7"><?= $invoice->issue_date?->format('d/m/Y') ?></dd>
                            <dt class="col-sm-5">Fecha Vencimiento</dt>
                            <dd class="col-sm-7"><?= $invoice->due_date?->format('d/m/Y') ?></dd>
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
                            <td><?= $history->created?->format('d/m/Y H:i') ?></td>
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

    <!-- Panel Lateral: Conciliación -->
    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-secondary text-white"><h6 class="mb-0">Revisión</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">Confirmado por</dt>
                    <dd class="col-6"><?= $invoice->hasValue('confirmed_by_user') ? h($invoice->confirmed_by_user->full_name) : '<span class="text-muted">—</span>' ?></dd>
                    <dt class="col-6">Aprobación Área</dt>
                    <dd class="col-6">
                        <?php
                        $approvalClass = match($invoice->area_approval) {
                            'Aprobada' => 'bg-success',
                            'Rechazada' => 'bg-danger',
                            default => 'bg-secondary',
                        };
                        ?>
                        <span class="badge <?= $approvalClass ?>"><?= h($invoice->area_approval) ?></span>
                    </dd>
                    <dt class="col-6">Fecha Aprobación</dt>
                    <dd class="col-6"><?= $invoice->area_approval_date?->format('d/m/Y') ?: '—' ?></dd>
                    <dt class="col-6">Validación DIAN</dt>
                    <dd class="col-6">
                        <?php
                        $dianClass = match($invoice->dian_validation) {
                            'Aprobada' => 'bg-success',
                            'Rechazado' => 'bg-danger',
                            default => 'bg-secondary',
                        };
                        ?>
                        <span class="badge <?= $dianClass ?>"><?= h($invoice->dian_validation) ?></span>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white"><h6 class="mb-0">Contabilidad</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">Causada</dt>
                    <dd class="col-6"><?= $invoice->accrued ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-secondary">No</span>' ?></dd>
                    <dt class="col-6">Fecha Causación</dt>
                    <dd class="col-6"><?= $invoice->accrual_date?->format('d/m/Y') ?: '—' ?></dd>
                    <dt class="col-6">Lista para Pago</dt>
                    <dd class="col-6"><?= h($invoice->ready_for_payment) ?: '—' ?></dd>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white"><h6 class="mb-0">Tesorería</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">Estado Pago</dt>
                    <dd class="col-6"><?= h($invoice->payment_status) ?: '—' ?></dd>
                    <dt class="col-6">Fecha Pago</dt>
                    <dd class="col-6"><?= $invoice->payment_date?->format('d/m/Y') ?: '—' ?></dd>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><h6 class="mb-0">Registro</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">Registrado por</dt>
                    <dd class="col-6"><?= $invoice->hasValue('registered_by_user') ? h($invoice->registered_by_user->full_name) : '' ?></dd>
                    <dt class="col-6">Creado</dt>
                    <dd class="col-6"><?= $invoice->created?->format('d/m/Y H:i') ?></dd>
                    <dt class="col-6">Modificado</dt>
                    <dd class="col-6"><?= $invoice->modified?->format('d/m/Y H:i') ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

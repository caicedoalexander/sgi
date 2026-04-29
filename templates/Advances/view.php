<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var iterable<\App\Model\Entity\Invoice> $linkedInvoices
 * @var float $linkedTotal
 */
$this->assign('title', 'Anticipo #' . $invoice->id);
$leg = $invoice->advance_legalization ?? null;
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h3 mb-0">Anticipo #<?= h($invoice->id) ?></h2>
            <small class="text-muted"><?= h($invoice->detail) ?></small>
        </div>
        <?= $this->Html->link('Editar', ['controller' => 'Invoices', 'action' => 'edit', $invoice->id], ['class' => 'btn btn-outline-secondary']) ?>
    </div>

    <?= $this->element('pipeline_progress', [
        'currentStatus' => $invoice->pipeline_status,
        'isRejected' => false,
    ]) ?>

    <?php if ($leg): ?>
        <h5 class="mt-4">Legalización</h5>
        <?= $this->element('advance_legalization_progress', ['currentStatus' => $leg->status]) ?>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header">Datos del Anticipo</div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Beneficiario</dt>
                <dd class="col-sm-9"><?= h($invoice->provider->name ?? ($invoice->employee->full_name ?? '—')) ?></dd>
                <dt class="col-sm-3">Monto</dt>
                <dd class="col-sm-9">$<?= number_format((float)$invoice->amount, 0, ',', '.') ?></dd>
                <dt class="col-sm-3">Centro de Operación</dt>
                <dd class="col-sm-9"><?= h($invoice->operation_center->name ?? '—') ?></dd>
                <dt class="col-sm-3">Estado pipeline</dt>
                <dd class="col-sm-9"><?= h($invoice->pipeline_status) ?></dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Facturas vinculadas</span>
            <?php if ($leg && $leg->status === \App\Constants\AdvanceConstants::STATUS_VALIDACION): ?>
                <button type="button" class="btn btn-sm sgi-btn-primary" data-bs-toggle="modal" data-bs-target="#advanceLinkModal">
                    <i class="bi bi-link-45deg"></i> Agregar facturas
                </button>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>#</th><th>Beneficiario</th><th>Fecha</th><th class="text-end">Monto</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($linkedInvoices as $li): ?>
                    <tr>
                        <td><?= h($li->invoice_number ?: $li->id) ?></td>
                        <td><?= h($li->provider->name ?? ($li->employee->full_name ?? '—')) ?></td>
                        <td><?= $li->issue_date ? $li->issue_date->format('d/m/Y') : '—' ?></td>
                        <td class="text-end">$<?= number_format((float)$li->amount, 0, ',', '.') ?></td>
                        <td><span class="badge bg-secondary"><?= h($li->pipeline_status) ?></span></td>
                        <td><?= $this->Html->link('<i class="bi bi-eye"></i>', ['controller' => 'Invoices', 'action' => 'view', $li->id], ['class' => 'btn btn-sm btn-outline-secondary', 'escape' => false]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light"><td colspan="3" class="text-end fw-bold">Total vinculado</td><td class="text-end fw-bold">$<?= number_format($linkedTotal, 0, ',', '.') ?></td><td colspan="2"></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

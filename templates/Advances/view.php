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
        'pipelineStatuses' => \App\Constants\InvoiceConstants::PIPELINE_STATUSES,
        'pipelineLabels' => \App\Service\InvoicePipelineService::STATUS_LABELS,
        'isRejected' => false,
        'paymentStatus' => $invoice->payment_status ?? null,
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

    <?php if ($leg): ?>
        <?= $this->element('advance_link_modal', ['leg' => $leg]) ?>

        <?php if ($leg->status === \App\Constants\AdvanceConstants::STATUS_VALIDACION): ?>
            <div class="card mt-3">
                <div class="card-header">Validación</div>
                <div class="card-body">
                    <?= $this->Form->create(null, ['url' => ['action' => 'uploadRelationDocument', $leg->id], 'type' => 'file']) ?>
                        <div class="mb-2">
                            <label class="form-label">Relación de facturas (PDF)</label>
                            <?= $this->Form->control('relation_document', ['type' => 'file', 'class' => 'form-control', 'label' => false, 'required' => true]) ?>
                        </div>
                        <button type="submit" class="btn btn-outline-primary btn-sm">Adjuntar</button>
                    <?= $this->Form->end() ?>
                    <hr>
                    <?= $this->Form->postLink(
                        'Pasar a Revisión y Firmas',
                        ['action' => 'moveToRevision', $leg->id],
                        ['class' => 'btn sgi-btn-primary', 'confirm' => '¿Pasar a Revisión y Firmas?'],
                    ) ?>
                </div>
            </div>
        <?php elseif ($leg->status === \App\Constants\AdvanceConstants::STATUS_REVISION_FIRMAS): ?>
            <div class="card mt-3">
                <div class="card-header">Revisión y Firmas</div>
                <div class="card-body">
                    <?= $this->Form->postLink('Marcar como firmado', ['action' => 'markSigned', $leg->id], ['class' => 'btn btn-success me-2']) ?>
                    <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#advReturnModal">Devolver a Validación</button>
                </div>
            </div>
            <div class="modal fade" id="advReturnModal" tabindex="-1"><div class="modal-dialog">
                <?= $this->Form->create(null, ['url' => ['action' => 'returnToValidacion', $leg->id]]) ?>
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title">Devolver a Validación</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <label class="form-label">Motivo *</label>
                        <?= $this->Form->control('reason', ['type' => 'textarea', 'rows' => 3, 'class' => 'form-control', 'required' => true, 'label' => false]) ?>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-warning">Devolver</button></div>
                </div>
                <?= $this->Form->end() ?>
            </div></div>
        <?php elseif ($leg->status === \App\Constants\AdvanceConstants::STATUS_CONTABILIDAD): ?>
            <?php
            $legService = new \App\Service\AdvanceLegalizationService();
            $diff = $legService->getDifference($leg);
            $linkedSum = $legService->getLinkedTotal($leg);
            $advanceTotal = (float)$invoice->amount;
            ?>
            <div class="card mt-3">
                <div class="card-header">Contabilidad — cierre</div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-3">Total Anticipo</dt><dd class="col-sm-9">$<?= number_format($advanceTotal, 0, ',', '.') ?></dd>
                        <dt class="col-sm-3">Total facturas vinculadas</dt><dd class="col-sm-9">$<?= number_format($linkedSum, 0, ',', '.') ?></dd>
                        <dt class="col-sm-3">Diferencia</dt><dd class="col-sm-9">
                            <span class="badge bg-<?= abs($diff) < 0.005 ? 'success' : ($diff > 0 ? 'warning text-dark' : 'danger') ?>">
                                $<?= number_format($diff, 0, ',', '.') ?>
                            </span>
                        </dd>
                    </dl>
                    <?php if (abs($diff) < 0.005): ?>
                        <?= $this->Form->postLink('Marcar legalizada (caso exacto)', ['action' => 'markExact', $leg->id], ['class' => 'btn btn-success']) ?>
                    <?php endif; ?>
                    <?php if ($diff > 0.005): ?>
                        <hr>
                        <?= $this->Form->create(null, ['url' => ['action' => 'registerShortage', $leg->id]]) ?>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">Monto del faltante</label>
                                    <input type="text" name="shortage_amount" class="form-control currency-input"
                                           value="<?= number_format($diff, 0, ',', '.') ?>" required>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <button type="submit" class="btn btn-warning">Registrar faltante</button>
                                </div>
                            </div>
                        <?= $this->Form->end() ?>
                    <?php elseif ($diff < -0.005): ?>
                        <hr>
                        <?= $this->Form->create(null, ['url' => ['action' => 'registerSurplus', $leg->id]]) ?>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">Monto del sobrante</label>
                                    <input type="text" name="surplus_amount" class="form-control currency-input"
                                           value="<?= number_format(abs($diff), 0, ',', '.') ?>" required>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <button type="submit" class="btn btn-danger">Registrar sobrante (reintegro a beneficiario)</button>
                                </div>
                            </div>
                        <?= $this->Form->end() ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($leg->status === \App\Constants\AdvanceConstants::STATUS_TESORERIA && $leg->case_type === \App\Constants\AdvanceConstants::CASE_SOBRANTE): ?>
            <div class="card mt-3">
                <div class="card-header">Tesorería — registrar reintegro al beneficiario</div>
                <div class="card-body">
                    <?php if ($leg->surplus_payment_id): ?>
                        <div class="alert alert-info mb-0">
                            Reintegro #<?= h($leg->surplus_payment_id) ?> registrado. Esperando autorización por el Contador en
                            <?= $this->Html->link('Aut. Pago', ['controller' => 'Invoices', 'action' => 'edit', $leg->advance_invoice_id]) ?>.
                        </div>
                    <?php else: ?>
                        <?php
                        $bankingEntities = \Cake\ORM\TableRegistry::getTableLocator()->get('BankingEntities')->find('list')->all();
                        ?>
                        <?= $this->Form->create(null, ['url' => ['action' => 'registerRefund', $leg->id]]) ?>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label">Entidad bancaria *</label>
                                    <?= $this->Form->select('banking_entity_id', $bankingEntities, ['class' => 'form-select select2', 'required' => true, 'empty' => '— Seleccione —']) ?>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Fecha *</label>
                                    <input type="text" name="payment_date" class="form-control flatpickr-date" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Monto</label>
                                    <input type="text" class="form-control" value="$<?= number_format((float)$leg->surplus_amount, 0, ',', '.') ?>" disabled>
                                </div>
                            </div>
                            <div class="mt-3 text-end">
                                <button type="submit" class="btn btn-danger">Registrar reintegro</button>
                            </div>
                        <?= $this->Form->end() ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($leg->status === \App\Constants\AdvanceConstants::STATUS_TESORERIA && $leg->case_type === \App\Constants\AdvanceConstants::CASE_FALTANTE): ?>
            <div class="card mt-3">
                <div class="card-header">Tesorería — confirmar consignación del faltante</div>
                <div class="card-body">
                    <p>Monto pendiente: <strong>$<?= number_format((float)$leg->shortage_amount, 0, ',', '.') ?></strong></p>
                    <?= $this->Form->create(null, ['url' => ['action' => 'confirmShortage', $leg->id], 'type' => 'file']) ?>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label">N.º comprobante *</label>
                                <input type="text" name="receipt_number" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Fecha</label>
                                <input type="text" name="received_at" class="form-control flatpickr-date">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Soporte (PDF/imagen)</label>
                                <input type="file" name="receipt_file" class="form-control">
                            </div>
                        </div>
                        <div class="mt-3 text-end">
                            <button type="submit" class="btn btn-success">Confirmar consignación</button>
                        </div>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        <?php elseif ($leg->status === \App\Constants\AdvanceConstants::STATUS_LEGALIZADA): ?>
            <div class="alert alert-success mt-3">
                <i class="bi bi-check-circle me-1"></i> Legalizada el <?= h($leg->legalized_at) ?> (caso <?= h($leg->case_type) ?>).
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

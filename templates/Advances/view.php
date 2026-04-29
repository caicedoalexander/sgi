<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var iterable<\App\Model\Entity\Invoice> $linkedInvoices
 * @var float $linkedTotal
 */

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationService;
use App\Service\InvoicePipelineService;

$this->assign('title', 'Anticipo #' . $invoice->id);

$leg = $invoice->advance_legalization ?? null;

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
$legalizationBadge = [
    AdvanceConstants::STATUS_VALIDACION       => 'bg-info text-dark',
    AdvanceConstants::STATUS_REVISION_FIRMAS  => 'bg-primary',
    AdvanceConstants::STATUS_CONTABILIDAD     => 'bg-warning text-dark',
    AdvanceConstants::STATUS_TESORERIA        => 'bg-warning text-dark',
    AdvanceConstants::STATUS_LEGALIZADA       => 'bg-success',
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

<!-- Card principal -->
<div class="card card-primary mb-4">
    <!-- Header -->
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
                    <?php if ($leg): ?>
                    <span class="badge <?= $legalizationBadge[$leg->status] ?? 'bg-dark' ?>">
                        <i class="bi bi-clipboard-check me-1"></i>
                        Legalización: <?= h(AdvanceConstants::STATUS_LABELS[$leg->status] ?? $leg->status) ?>
                    </span>
                    <?php endif; ?>
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

    <!-- Pipeline factura -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('pipeline_progress', [
            'currentStatus' => $invoice->pipeline_status,
            'pipelineStatuses' => InvoiceConstants::PIPELINE_STATUSES,
            'pipelineLabels' => InvoicePipelineService::STATUS_LABELS,
            'isRejected' => false,
            'paymentStatus' => $invoice->payment_status ?? null,
        ]) ?>
    </div>

    <?php if ($leg): ?>
    <!-- Pipeline legalización -->
    <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border-color);">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="text-uppercase fw-semibold flex-shrink-0"
                  style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">Legalización</span>
            <div style="flex:1;height:1px;background:var(--border-color);"></div>
        </div>
        <?= $this->element('advance_legalization_progress', ['currentStatus' => $leg->status]) ?>
    </div>
    <?php endif; ?>

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

<!-- Facturas vinculadas -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-link-45deg"></i>
        <span>Facturas vinculadas</span>
        <span class="sgi-folder-count"><?= is_object($linkedInvoices) && method_exists($linkedInvoices, 'count') ? $linkedInvoices->count() : count($linkedInvoices) ?></span>
        <?php if ($leg && $leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
        <div class="ms-auto">
            <button type="button" class="btn btn-sm sgi-btn-primary" data-bs-toggle="modal" data-bs-target="#advanceLinkModal">
                <i class="bi bi-plus-lg me-1"></i>Vincular facturas
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php if (!$linkedInvoices || (is_object($linkedInvoices) && $linkedInvoices->count() === 0)): ?>
    <div class="p-3 text-center text-muted" style="font-size:.875rem;">
        <i class="bi bi-inbox me-1"></i>Sin facturas vinculadas
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th># Factura</th>
                    <th>Beneficiario</th>
                    <th>Fecha emisión</th>
                    <th class="text-end">Monto</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($linkedInvoices as $li): ?>
                <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $li->id]) ?>">
                    <td style="font-family:monospace;font-weight:600;"><?= h($li->invoice_number ?: '#' . $li->id) ?></td>
                    <td><?= h($li->provider->name ?? ($li->employee->full_name ?? '—')) ?></td>
                    <td><?= $li->issue_date?->format('d/m/Y') ?? '—' ?></td>
                    <td class="text-end" style="font-weight:600;">$ <?= $this->Number->format((float)$li->amount, ['places' => 2]) ?></td>
                    <td>
                        <span class="badge <?= $pipelineBadge[$li->pipeline_status] ?? 'bg-dark' ?>">
                            <?= $pipelineLabels[$li->pipeline_status] ?? h($li->pipeline_status) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <?php if ($leg && $leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
                        <?= $this->Form->postLink(
                            '<i class="bi bi-x-lg"></i>',
                            ['action' => 'unlinkInvoice', $invoice->id, $li->id],
                            ['class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'confirm' => '¿Desvincular esta factura del anticipo?', 'title' => 'Desvincular']
                        ) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#fafafa;">
                    <td colspan="3" class="text-end" style="font-weight:600;color:#666;">Total vinculado</td>
                    <td class="text-end" style="font-weight:700;color:var(--primary-color);">
                        $ <?= $this->Number->format((float)$linkedTotal, ['places' => 2]) ?>
                    </td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($leg): ?>
<?= $this->element('advance_link_modal', ['leg' => $leg]) ?>

<?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
<!-- Validación -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-clipboard-check"></i>
        <span>Validación</span>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3" style="font-size:.85rem;">
            Adjunte la <strong>relación de facturas (PDF)</strong> y avance a Revisión y Firmas.
        </p>
        <?= $this->Form->create(null, ['url' => ['action' => 'uploadRelationDocument', $leg->id], 'type' => 'file']) ?>
        <div class="row g-2 align-items-end mb-3">
            <div class="col-md-8">
                <label class="form-label">Relación de facturas (PDF)</label>
                <input type="file" name="relation_document" class="form-control" required>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-paperclip me-1"></i>Adjuntar
                </button>
            </div>
        </div>
        <?= $this->Form->end() ?>

        <hr>
        <?= $this->Form->postLink(
            '<i class="bi bi-arrow-right-circle me-1"></i>Pasar a Revisión y Firmas',
            ['action' => 'moveToRevision', $leg->id],
            ['class' => 'btn sgi-btn-primary', 'escape' => false, 'confirm' => '¿Pasar a Revisión y Firmas?']
        ) ?>
    </div>
</div>
<?php elseif ($leg->status === AdvanceConstants::STATUS_REVISION_FIRMAS): ?>
<!-- Revisión y Firmas -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-pen"></i>
        <span>Revisión y Firmas</span>
    </div>
    <div class="card-body d-flex gap-2 flex-wrap">
        <?= $this->Form->postLink(
            '<i class="bi bi-check-circle me-1"></i>Marcar como firmado',
            ['action' => 'markSigned', $leg->id],
            ['class' => 'btn btn-success', 'escape' => false]
        ) ?>
        <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#advReturnModal">
            <i class="bi bi-arrow-return-left me-1"></i>Devolver a Validación
        </button>
    </div>
</div>

<div class="modal fade" id="advReturnModal" tabindex="-1">
    <div class="modal-dialog">
        <?= $this->Form->create(null, ['url' => ['action' => 'returnToValidacion', $leg->id]]) ?>
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Devolver a Validación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Motivo *</label>
                <?= $this->Form->control('reason', ['type' => 'textarea', 'rows' => 3, 'class' => 'form-control', 'required' => true, 'label' => false]) ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-warning">Devolver</button>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
<?php elseif ($leg->status === AdvanceConstants::STATUS_CONTABILIDAD): ?>
<?php
$legService = new AdvanceLegalizationService();
$diff = $legService->getDifference($leg);
$linkedSum = $legService->getLinkedTotal($leg);
$advanceTotal = (float)$invoice->amount;
$diffBadgeClass = abs($diff) < 0.005 ? 'bg-success' : ($diff > 0 ? 'bg-warning text-dark' : 'bg-danger');
?>
<!-- Contabilidad — cierre -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-calculator"></i>
        <span>Contabilidad — cierre de legalización</span>
    </div>
    <div class="row g-0">
        <div class="col-md-12">
            <div class="sgi-data-row">
                <span class="sgi-data-label">Total Anticipo</span>
                <span class="sgi-data-value">$ <?= $this->Number->format($advanceTotal, ['places' => 2]) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Total facturas vinculadas</span>
                <span class="sgi-data-value">$ <?= $this->Number->format($linkedSum, ['places' => 2]) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Diferencia</span>
                <span class="sgi-data-value">
                    <span class="badge <?= $diffBadgeClass ?>">
                        $ <?= $this->Number->format($diff, ['places' => 2]) ?>
                    </span>
                    <?php if (abs($diff) < 0.005): ?>
                    <small class="text-muted ms-2">Caso exacto</small>
                    <?php elseif ($diff > 0): ?>
                    <small class="text-muted ms-2">Faltante: el beneficiario debe consignar</small>
                    <?php else: ?>
                    <small class="text-muted ms-2">Sobrante: la empresa debe reintegrar</small>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
    <div class="card-body" style="border-top:1px solid var(--border-color);">
        <?php if (abs($diff) < 0.005): ?>
        <?= $this->Form->postLink(
            '<i class="bi bi-check-circle me-1"></i>Marcar legalizada (caso exacto)',
            ['action' => 'markExact', $leg->id],
            ['class' => 'btn btn-success', 'escape' => false]
        ) ?>
        <?php elseif ($diff > 0.005): ?>
        <?= $this->Form->create(null, ['url' => ['action' => 'registerShortage', $leg->id]]) ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label">Monto del faltante</label>
                <input type="text" name="shortage_amount" class="form-control currency-input"
                       value="<?= number_format($diff, 0, ',', '.') ?>" required>
            </div>
            <div class="col-md-6">
                <button type="submit" class="btn btn-warning w-100">
                    <i class="bi bi-arrow-down-circle me-1"></i>Registrar faltante
                </button>
            </div>
        </div>
        <?= $this->Form->end() ?>
        <?php else: ?>
        <?= $this->Form->create(null, ['url' => ['action' => 'registerSurplus', $leg->id]]) ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label">Monto del sobrante</label>
                <input type="text" name="surplus_amount" class="form-control currency-input"
                       value="<?= number_format(abs($diff), 0, ',', '.') ?>" required>
            </div>
            <div class="col-md-6">
                <button type="submit" class="btn btn-danger w-100">
                    <i class="bi bi-arrow-up-circle me-1"></i>Registrar sobrante (reintegro)
                </button>
            </div>
        </div>
        <?= $this->Form->end() ?>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($leg->status === AdvanceConstants::STATUS_TESORERIA && $leg->case_type === AdvanceConstants::CASE_FALTANTE): ?>
<!-- Tesorería — confirmar consignación faltante -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-bank"></i>
        <span>Tesorería — confirmar consignación del faltante</span>
    </div>
    <div class="card-body">
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-info-circle-fill"></i>
            <span>Monto pendiente de consignar: <strong>$ <?= $this->Number->format((float)$leg->shortage_amount, ['places' => 2]) ?></strong></span>
        </div>
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
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-circle me-1"></i>Confirmar consignación
            </button>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
<?php elseif ($leg->status === AdvanceConstants::STATUS_TESORERIA && $leg->case_type === AdvanceConstants::CASE_SOBRANTE): ?>
<!-- Tesorería — registrar reintegro al beneficiario -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-bank"></i>
        <span>Tesorería — registrar reintegro al beneficiario</span>
    </div>
    <div class="card-body">
        <?php if ($leg->surplus_payment_id): ?>
        <div class="alert alert-info d-flex align-items-center gap-2 mb-0">
            <i class="bi bi-info-circle-fill"></i>
            <span>
                Reintegro #<?= h($leg->surplus_payment_id) ?> registrado. Esperando autorización por el Contador en
                <?= $this->Html->link('Aut. Pago de la factura', ['controller' => 'Invoices', 'action' => 'edit', $leg->advance_invoice_id]) ?>.
            </span>
        </div>
        <?php else: ?>
        <?php
        $bankingEntities = \Cake\ORM\TableRegistry::getTableLocator()->get('BankingEntities')->find('list')->all();
        ?>
        <?= $this->Form->create(null, ['url' => ['action' => 'registerRefund', $leg->id]]) ?>
        <div class="row g-2">
            <div class="col-md-5">
                <label class="form-label">Entidad bancaria *</label>
                <?= $this->Form->select('banking_entity_id', $bankingEntities, ['class' => 'form-select select2-enable', 'required' => true, 'empty' => '-- Seleccione --']) ?>
            </div>
            <div class="col-md-3">
                <label class="form-label">Fecha *</label>
                <input type="text" name="payment_date" class="form-control flatpickr-date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Monto</label>
                <input type="text" class="form-control" value="$ <?= $this->Number->format((float)$leg->surplus_amount, ['places' => 2]) ?>" disabled>
            </div>
        </div>
        <div class="mt-3 text-end">
            <button type="submit" class="btn btn-danger">
                <i class="bi bi-arrow-up-circle me-1"></i>Registrar reintegro
            </button>
        </div>
        <?= $this->Form->end() ?>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($leg->status === AdvanceConstants::STATUS_LEGALIZADA): ?>
<div class="alert alert-success d-flex align-items-center gap-2">
    <i class="bi bi-check-circle-fill fs-5"></i>
    <span>
        <strong>Legalizada</strong>
        <?php if ($leg->legalized_at): ?>
            el <?= h($leg->legalized_at) ?>
        <?php endif; ?>
        <?php if ($leg->case_type): ?>
            (caso <?= h(AdvanceConstants::CASE_TYPES[array_search($leg->case_type, AdvanceConstants::CASE_TYPES, true)] ?? $leg->case_type) ?>).
        <?php endif; ?>
    </span>
</div>
<?php endif; ?>
<?php endif; ?>

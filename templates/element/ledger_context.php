<?php
/**
 * Contextual read-only ledger for invoice pipeline screens.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 */

$amount = (float)($invoice->amount ?? 0);
$issueDate  = $invoice->issue_date ?? null;
$dueDate    = $invoice->due_date ?? null;
$accrualDate = $invoice->accrual_date ?? null;

$fmtDate = function ($d) {
    if (!$d) {
        return '—';
    }
    if (is_object($d) && method_exists($d, 'format')) {
        return $d->format('d/m/Y');
    }
    return date('d/m/Y', strtotime((string)$d));
};
?>
<div class="sgi-ledger mb-4">
    <div class="row g-3">
        <div class="col-md-3">
            <small class="text-uppercase text-muted">N° Factura</small>
            <div class="fw-semibold"><?= h($invoice->invoice_number ?? '—') ?></div>
        </div>
        <div class="col-md-3">
            <small class="text-uppercase text-muted">Proveedor</small>
            <div><?= h($invoice->provider->name ?? '—') ?></div>
        </div>
        <div class="col-md-2">
            <small class="text-uppercase text-muted">Monto</small>
            <div>$ <?= number_format($amount, 0, ',', '.') ?></div>
        </div>
        <div class="col-md-2">
            <small class="text-uppercase text-muted">Emisión</small>
            <div><?= h($fmtDate($issueDate)) ?></div>
        </div>
        <div class="col-md-2">
            <small class="text-uppercase text-muted">Vence</small>
            <div><?= h($fmtDate($dueDate)) ?></div>
        </div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-md-3">
            <small class="text-uppercase text-muted">Centro de Operación</small>
            <div><?= h($invoice->operation_center->name ?? '—') ?></div>
        </div>
        <div class="col-md-3">
            <small class="text-uppercase text-muted">Tipo de Gasto</small>
            <div><?= h($invoice->expense_type->name ?? '—') ?></div>
        </div>
        <div class="col-md-3">
            <small class="text-uppercase text-muted">Centro de Costo</small>
            <div><?= h($invoice->cost_center->name ?? '—') ?></div>
        </div>
        <div class="col-md-3">
            <small class="text-uppercase text-muted">Fecha Causación</small>
            <div><?= h($fmtDate($accrualDate)) ?></div>
        </div>
    </div>
    <?php if (!empty($invoice->detail)): ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <small class="text-uppercase text-muted">Detalle</small>
            <div><?= h($invoice->detail) ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

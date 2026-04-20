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

$items = [
    ['label' => 'N° Factura', 'value' => h($invoice->invoice_number ?? '—')],
    ['label' => 'Proveedor', 'value' => h($invoice->provider->name ?? '—')],
    ['label' => 'Monto', 'value' => '$ ' . number_format($amount, 0, ',', '.'), 'class' => '--amount'],
    ['label' => 'Emisión', 'value' => h($fmtDate($issueDate))],
    ['label' => 'Vence', 'value' => h($fmtDate($dueDate))],
    ['label' => 'Causación', 'value' => h($fmtDate($accrualDate))],
    ['label' => 'Centro de Operación', 'value' => h($invoice->operation_center->name ?? '—')],
    ['label' => 'Tipo de Gasto', 'value' => h($invoice->expense_type->name ?? '—')],
    ['label' => 'Centro de Costo', 'value' => h($invoice->cost_center->name ?? '—')],
];
?>
<div class="sgi-ledger mb-4">
    <?php foreach ($items as $item): ?>
        <div class="sgi-ledger-item">
            <div class="sgi-ledger-label"><?= $item['label'] ?></div>
            <div class="sgi-ledger-value <?= $item['class'] ?? '' ?>"><?= $item['value'] ?></div>
        </div>
    <?php endforeach; ?>
    <?php if (!empty($invoice->detail)): ?>
        <div class="sgi-ledger-item" style="grid-column:1 / -1;">
            <div class="sgi-ledger-label">Detalle</div>
            <div class="sgi-ledger-value" style="white-space:normal;"><?= h($invoice->detail) ?></div>
        </div>
    <?php endif; ?>
</div>

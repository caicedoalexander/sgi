<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Invoice> $invoices
 * @var string $roleName
 */
$this->assign('title', 'Facturas');

$pipelineBadges = [
    'revision'     => ['Revisión', 'bg-secondary'],
    'area_approved' => ['Área Aprobada', 'bg-info text-dark'],
    'accrued'      => ['Causada', 'bg-primary'],
    'treasury'     => ['Tesorería', 'bg-warning text-dark'],
    'paid'         => ['Pagada', 'bg-success'],
];
?>

<div class="card border-0">
    <div class="table-responsive border-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>N. Factura</th>
                    <th>Tipo</th>
                    <th>Proveedor</th>
                    <th><?= $this->Paginator->sort('issue_date', 'Emisión') ?></th>
                    <th><?= $this->Paginator->sort('due_date', 'Vencimiento') ?></th>
                    <th class="text-end">Valor</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $invoice): ?>
                <?php $ps = $pipelineBadges[$invoice->pipeline_status] ?? ['Desconocido', 'bg-dark']; ?>
                <tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'edit', $invoice->id]) ?>">
                    <td><?= h($invoice->invoice_number ?? '-') ?></td>
                    <td><?= h($invoice->document_type) ?></td>
                    <td><?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '' ?></td>
                    <td><?= $invoice->issue_date?->format('d/m/Y') ?></td>
                    <td><?= $invoice->due_date?->format('d/m/Y') ?></td>
                    <td class="text-end fw-semibold">$ <?= number_format((float)$invoice->amount, 0, ',', '.') ?></td>
                    <td><span class="badge <?= $ps[1] ?>"><?= $ps[0] ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($invoices->toArray())): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        No hay facturas en tu bandeja actual.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>

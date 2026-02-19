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
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Facturas</h1>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1"></i>Nueva Factura',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th>No. Factura</th>
                    <th><?= $this->Paginator->sort('document_type', 'Tipo') ?></th>
                    <th><?= $this->Paginator->sort('Providers.name', 'Proveedor') ?></th>
                    <th><?= $this->Paginator->sort('issue_date', 'Emisión') ?></th>
                    <th><?= $this->Paginator->sort('due_date', 'Vencimiento') ?></th>
                    <th class="text-end"><?= $this->Paginator->sort('amount', 'Valor') ?></th>
                    <th><?= $this->Paginator->sort('pipeline_status', 'Estado') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $invoice): ?>
                <?php $ps = $pipelineBadges[$invoice->pipeline_status] ?? ['Desconocido', 'bg-dark']; ?>
                <tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'edit', $invoice->id]) ?>">
                    <td class="fw-semibold text-muted"><?= $invoice->id ?></td>
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
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted"><?= $this->Paginator->counter('Mostrando {{start}}-{{end}} de {{count}}') ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?= $this->Paginator->first('«', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->prev('‹', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->numbers(['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->next('›', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->last('»', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
            </ul>
        </nav>
    </div>
</div>

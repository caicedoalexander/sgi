<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Invoice> $invoices
 * @var string $roleName
 * @var string[] $visibleStatuses
 * @var \Cake\ORM\ResultSet $providers
 * @var \Cake\ORM\ResultSet $operationCenters
 * @var \Cake\ORM\ResultSet $expenseTypes
 */
$this->assign('title', 'Facturas');

$pipelineBadges = [
    'registro'      => ['Registro',      'bg-secondary'],
    'aprobacion'    => ['Aprobación',    'bg-info text-dark'],
    'contabilidad'  => ['Contabilidad',  'bg-primary'],
    'tesoreria'     => ['Tesorería',     'bg-warning text-dark'],
    'pagada'        => ['Pagada',        'bg-success'],
];

$query = $this->request->getQueryParams();
$hasFilters = !empty(array_filter($query, fn($v) => $v !== '' && $v !== null));
$pipelineOptions = [
    'registro' => 'Registro',
    'aprobacion' => 'Aprobación',
    'contabilidad' => 'Contabilidad',
    'tesoreria' => 'Tesorería',
    'pagada' => 'Pagada',
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Facturas</span>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1"></i>Nueva Factura',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
</div>

<!-- Search & Filters -->
<div class="sgi-search-bar mb-3">
    <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
    <div class="d-flex gap-2">
        <div class="flex-grow-1">
            <?= $this->Form->control('search', [
                'label' => false,
                'type' => 'text',
                'class' => 'form-control',
                'placeholder' => 'Buscar por número, orden de compra, detalle o proveedor…',
                'value' => $this->request->getQuery('search', ''),
            ]) ?>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
        <button type="button" class="btn btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#invoiceFilters" title="Filtros avanzados">
            <i class="bi bi-funnel"></i>
        </button>
        <?php if ($hasFilters): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x-lg"></i> Limpiar',
                ['action' => 'index'],
                ['class' => 'btn btn-outline-danger', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>

    <div class="collapse <?= $hasFilters ? 'show' : '' ?>" id="invoiceFilters">
        <div class="sgi-filters-section mt-2">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="sgi-filter-label">Proveedor</label>
                    <?= $this->Form->select('provider_id', $providers, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('provider_id', ''),
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="sgi-filter-label">Centro Op.</label>
                    <?= $this->Form->select('operation_center_id', $operationCenters, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('operation_center_id', ''),
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="sgi-filter-label">Tipo Gasto</label>
                    <?= $this->Form->select('expense_type_id', $expenseTypes, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('expense_type_id', ''),
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="sgi-filter-label">Estado</label>
                    <?= $this->Form->select('pipeline_status', $pipelineOptions, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('pipeline_status', ''),
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="sgi-filter-label">Desde</label>
                            <input type="text" name="date_from" class="form-control form-control-sm flatpickr-date"
                                   value="<?= h($this->request->getQuery('date_from', '')) ?>"
                                   placeholder="Fecha desde">
                        </div>
                        <div class="col-6">
                            <label class="sgi-filter-label">Hasta</label>
                            <input type="text" name="date_to" class="form-control form-control-sm flatpickr-date"
                                   value="<?= h($this->request->getQuery('date_to', '')) ?>"
                                   placeholder="Fecha hasta">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?= $this->Form->end() ?>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th style="width:160px;">Factura</th>
                    <th>Proveedor</th>
                    <th style="width:110px;"><?= $this->Paginator->sort('issue_date', 'Emisión') ?></th>
                    <th style="width:120px;"><?= $this->Paginator->sort('due_date', 'Vencimiento') ?></th>
                    <th style="width:140px;" class="text-end"><?= $this->Paginator->sort('amount', 'Valor') ?></th>
                    <th style="width:180px;">Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $invoice):
                    $ps             = $pipelineBadges[$invoice->pipeline_status] ?? ['Desconocido', 'bg-dark'];
                    $isRejected     = ($invoice->area_approval === 'Rechazada');
                    $isPartialPay   = ($invoice->pipeline_status === 'tesoreria' && $invoice->payment_status === 'Pago Parcial');
                    $isPaid         = ($invoice->pipeline_status === 'pagada');
                ?>
                <tr class="clickable-row<?= $isRejected ? ' table-danger' : '' ?>"
                    data-href="<?= $this->Url->build(['action' => 'edit', $invoice->id]) ?>">

                    <!-- Número de factura + tipo -->
                    <td>
                        <div class="fw-semibold"
                             style="font-family:monospace;font-size:.8rem;color:#111;letter-spacing:-.01em;">
                            <?= h($invoice->invoice_number ?: '—') ?>
                        </div>
                        <div style="font-size:.7rem;color:#bbb;margin-top:.1rem;text-transform:uppercase;letter-spacing:.04em;">
                            <?= h($invoice->document_type) ?>
                        </div>
                    </td>

                    <!-- Proveedor + centro de operación -->
                    <td>
                        <div style="font-size:.8125rem;font-weight:500;color:#222;line-height:1.3;">
                            <?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '<span class="text-muted">—</span>' ?>
                        </div>
                        <?php if ($invoice->hasValue('operation_center')): ?>
                        <div style="font-size:.7rem;color:#bbb;margin-top:.1rem;">
                            <?= h($invoice->operation_center->name) ?>
                        </div>
                        <?php endif; ?>
                    </td>

                    <!-- Fecha de emisión -->
                    <td style="font-size:.8125rem;color:#555;white-space:nowrap;">
                        <?= $invoice->issue_date?->format('d/m/Y') ?: '—' ?>
                    </td>

                    <!-- Fecha de vencimiento -->
                    <td style="white-space:nowrap;">
                        <?php
                        $dueDate = $invoice->due_date;
                        $today   = new \DateTime('today');
                        $overdue = $dueDate && !$isPaid && !$isRejected && $dueDate < $today;
                        ?>
                        <span style="font-size:.8125rem;<?= $overdue ? 'color:#dc3545;font-weight:600;' : 'color:#555;' ?>">
                            <?= $dueDate?->format('d/m/Y') ?: '—' ?>
                        </span>
                        <?php if ($overdue): ?>
                            <i class="bi bi-exclamation-circle-fill text-danger ms-1" style="font-size:.7rem;"
                               title="Vencida"></i>
                        <?php endif; ?>
                    </td>

                    <!-- Valor -->
                    <td class="text-end fw-semibold" style="white-space:nowrap;color:var(--primary-color);font-size:.875rem;">
                        $ <?= number_format((float)$invoice->amount, 0, ',', '.') ?>
                    </td>

                    <!-- Estado pipeline + badges secundarios -->
                    <td>
                        <div class="d-flex flex-wrap align-items-center gap-1">
                            <?php if ($isRejected): ?>
                                <span class="badge bg-danger"><?= $ps[0] ?></span>
                                <span class="badge bg-danger">Rechazada</span>
                            <?php else: ?>
                                <span class="badge <?= $ps[1] ?>"><?= $ps[0] ?></span>
                                <?php if ($isPartialPay): ?>
                                    <span class="badge bg-warning text-dark">Pago Parcial</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($invoices->toArray())): ?>
                <tr>
                    <td colspan="6">
                        <div class="sgi-doc-empty">
                            <i class="bi bi-inbox sgi-doc-empty-icon"></i>
                            No hay facturas en tu bandeja actual.
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>

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

use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

$isAllView      = $this->request->getParam('action') === 'all';
$isRejectedView = $this->request->getParam('action') === 'rejected';
$pageTitle = $isRejectedView ? 'Facturas Rechazadas'
           : ($isAllView     ? 'Todas las Facturas'
           :                   'Mis Facturas');
$this->assign('title', $pageTitle);

$query = $this->request->getQueryParams();
$hasFilters = !empty(array_filter($query, fn($v) => $v !== '' && $v !== null));
$pipelineOptions = InvoiceConstants::STATUS_LABELS;

// Pills soft por estado de pipeline (alineado al sistema de diseño v2).
$statusPills = [
    InvoiceConstants::STATUS_APROBACION        => 'pill-warning-soft',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'pill-secondary-soft',
    InvoiceConstants::STATUS_TESORERIA         => 'pill-info-soft',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
    InvoiceConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
    InvoiceConstants::STATUS_PAGADA            => 'pill-primary-soft',
    InvoiceConstants::STATUS_LEGALIZADA        => 'pill-primary-soft',
];

// Materializar el ResultSet para poder sumar y luego iterar.
$invoicesArr = is_array($invoices) ? $invoices : iterator_to_array($invoices, false);
$pageTotal = 0.0;
foreach ($invoicesArr as $inv) { $pageTotal += (float)$inv->amount; }
$invoices = $invoicesArr;

// Mes actual en español para la meta-línea del header.
$mesesEs = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$now = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

// Tabs por estado de pipeline (filtros directos vía query string).
$activeStatus = (string)$this->request->getQuery('pipeline_status', '');
$tabAction = $isRejectedView ? 'rejected' : ($isAllView ? 'all' : 'index');
$baseQuery = array_diff_key($query, ['pipeline_status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($tabAction, $baseQuery) {
    $params = ['action' => $tabAction];
    if ($status !== null) { $params['?'] = $baseQuery + ['pipeline_status' => $status]; }
    elseif (!empty($baseQuery)) { $params['?'] = $baseQuery; }
    return $params;
};
$tabs = [
    [null,                                             'Todas'],
    [InvoiceConstants::STATUS_APROBACION,              'En aprobación'],
    [InvoiceConstants::STATUS_CONTABILIDAD,            'Contabilidad'],
    [InvoiceConstants::STATUS_TESORERIA,               'Tesorería'],
    [InvoiceConstants::STATUS_AUTORIZACION_PAGO,       'Autorización'],
    [InvoiceConstants::STATUS_PAGADA,                  'Pagadas'],
];

// Pills para el flag ready_for_payment.
$readyForPaymentPills = [
    InvoiceConstants::READY_FOR_PAYMENT_SI          => 'pill-primary-soft',
    InvoiceConstants::READY_FOR_PAYMENT_PRIORITARIO => 'pill-danger-soft',
    InvoiceConstants::READY_FOR_PAYMENT_PSE         => 'pill-dark',
];

// Steps para la mini-bar (legalización = pipeline reducido de 3 pasos).
$pipelineStepsFull = InvoiceConstants::PIPELINE_STATUSES;
$pipelineStepsLeg  = [
    InvoiceConstants::STATUS_APROBACION,
    InvoiceConstants::STATUS_CONTABILIDAD,
    InvoiceConstants::STATUS_LEGALIZADA,
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div>
        <span class="sgi-page-title"><?= $pageTitle ?></span>
        <div class="sgi-body-faint mt-1" style="font-size:12px;">
            Período: <?= h($periodLabel) ?> ·
            <span class="sgi-fg-muted"><?= $this->Paginator->counter('{{count}} documentos') ?></span> ·
            <span class="sgi-fg-muted mono">$ <?= number_format($pageTotal, 0, ',', '.') ?></span>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['dian_crosschecks']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left-right" aria-hidden="true"></i>Cruce',
            ['controller' => 'DianCrosschecks', 'action' => 'add'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['dian_crosschecks']['can_view'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-clockwise" aria-hidden="true"></i>Cruces',
            ['controller' => 'DianCrosschecks', 'action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
        <?php endif; ?>
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'Invoices',
            'importable' => false,
            'canCreate' => !empty($userPermissions['invoices']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['invoices']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Factura',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filters -->
<div class="sgi-search-bar mb-3">
    <?= $this->Form->create(null, ['type' => 'get', 'url' => ['action' => $isRejectedView ? 'rejected' : ($isAllView ? 'all' : 'index')], 'valueSources' => ['query']]) ?>
    <div class="d-flex gap-2 align-items-center">
        <div class="sgi-input-icon">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="text" name="search"
                   class="form-control"
                   placeholder="Buscar por número, orden de compra, detalle o proveedor…"
                   value="<?= h($this->request->getQuery('search', '')) ?>"
                   aria-label="Buscar facturas">
        </div>
        <button type="button" class="btn btn-ghost"
                data-bs-toggle="collapse" data-bs-target="#invoiceFilters" aria-label="Filtros avanzados">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            <span>Filtros<?= $hasFilters ? ' · activos' : '' ?></span>
        </button>
        <?php if ($hasFilters): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x-lg" aria-hidden="true"></i> Limpiar',
                ['action' => $isRejectedView ? 'rejected' : ($isAllView ? 'all' : 'index')],
                ['class' => 'btn btn-ghost sgi-fg-danger', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>

    <div class="collapse <?= $hasFilters ? 'show' : '' ?>" id="invoiceFilters">
        <div class="sgi-filters-section mt-2">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="sgi-filter-label" for="filter-provider">Proveedor</label>
                    <?= $this->Form->select('provider_id', $providers, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('provider_id', ''),
                        'id'    => 'filter-provider',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="sgi-filter-label" for="filter-opcenter">Centro Op.</label>
                    <?= $this->Form->select('operation_center_id', $operationCenters, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('operation_center_id', ''),
                        'id'    => 'filter-opcenter',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="sgi-filter-label" for="filter-expense">Tipo Gasto</label>
                    <?= $this->Form->select('expense_type_id', $expenseTypes, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('expense_type_id', ''),
                        'id'    => 'filter-expense',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="sgi-filter-label" for="filter-status">Estado</label>
                    <?= $this->Form->select('pipeline_status', $pipelineOptions, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('pipeline_status', ''),
                        'id'    => 'filter-status',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="sgi-filter-label" for="filter-date-from">Desde</label>
                            <input type="text" name="date_from" id="filter-date-from"
                                   class="form-control form-control-sm flatpickr-date"
                                   value="<?= h($this->request->getQuery('date_from', '')) ?>"
                                   placeholder="Fecha desde">
                        </div>
                        <div class="col-6">
                            <label class="sgi-filter-label" for="filter-date-to">Hasta</label>
                            <input type="text" name="date_to" id="filter-date-to"
                                   class="form-control form-control-sm flatpickr-date"
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

<?php if (!$isRejectedView): ?>
<!-- Tabs por estado de pipeline -->
<div class="sgi-status-tabs mb-3" role="tablist" aria-label="Filtrar por estado">
    <?php foreach ($tabs as [$status, $label]):
        $isActive = ($activeStatus === ($status ?? ''));
        $color = match ($status) {
            InvoiceConstants::STATUS_APROBACION, InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'var(--warning-text)',
            InvoiceConstants::STATUS_CONTABILIDAD => 'var(--secondary-color)',
            InvoiceConstants::STATUS_TESORERIA    => 'var(--info-text)',
            InvoiceConstants::STATUS_PAGADA       => 'var(--primary-color)',
            default                               => 'var(--primary-color)',
        };
    ?>
        <?= $this->Html->link(
            ($isActive ? '<span class="sgi-status-tab-dot" style="background:' . $color . ';"></span>' : '') . h($label),
            $tabUrl($status),
            [
                'class' => 'sgi-status-tab' . ($isActive ? ' is-active' : ''),
                'escape' => false,
                'role' => 'tab',
                'aria-selected' => $isActive ? 'true' : 'false',
                'style' => $isActive ? 'color:' . $color : '',
            ]
        ) ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width:170px;">Factura</th>
                    <th>Proveedor</th>
                    <th style="width:110px;"><?= $this->Paginator->sort('issue_date', 'Emisión') ?></th>
                    <th style="width:120px;"><?= $this->Paginator->sort('due_date', 'Vencimiento') ?></th>
                    <th style="width:150px;" class="text-end"><?= $this->Paginator->sort('amount', 'Valor') ?></th>
                    <th style="width:220px;">Estado · Pipeline</th>
                    <th style="width:1%;white-space:nowrap;" aria-label="Observaciones"><i class="bi bi-chat-left-text" title="Observaciones" aria-hidden="true"></i></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $rowCount = 0;
                $today = new \DateTimeImmutable('today');
                foreach ($invoices as $invoice):
                    $rowCount++;
                    $row = InvoicePresentation::forRow($invoice, $today);
                    $isLegalization = $invoice->document_type === 'Legalización';
                    $steps = $isLegalization ? $pipelineStepsLeg : $pipelineStepsFull;
                    $stageIdx = array_search($invoice->pipeline_status, $steps, true);
                    if ($stageIdx === false) {
                        $stageIdx = -1;
                    }
                    $pillClass = $row->isRejected
                        ? 'pill-danger-soft'
                        : ($statusPills[$invoice->pipeline_status] ?? 'pill-muted');
                ?>
                <tr class="clickable-row<?= $row->isRejected ? ' table-danger' : '' ?>"
                    data-href="<?= $this->Url->build(['action' => $isAllView ? 'view' : 'edit', $invoice->id]) ?>">

                    <!-- Factura: número + tipo -->
                    <td>
                        <div class="mono sgi-fg-strong" style="font-size:12.5px;font-weight:700;letter-spacing:-.01em;">
                            <?= h($invoice->invoice_number ?: '—') ?>
                        </div>
                        <div class="sgi-label sgi-fg-faint" style="margin-top:2px;">
                            <?= h($invoice->document_type) ?>
                        </div>
                    </td>

                    <!-- Proveedor + centro de operación -->
                    <td>
                        <div class="sgi-fg-default" style="font-size:12.5px;font-weight:600;line-height:1.3;">
                            <?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '<span class="sgi-fg-faint">—</span>' ?>
                        </div>
                        <?php if ($invoice->hasValue('operation_center')): ?>
                        <div class="sgi-fg-faint d-inline-flex align-items-center gap-1" style="font-size:10.5px;margin-top:2px;">
                            <i class="bi bi-geo-alt" aria-hidden="true" style="font-size:10px;"></i>
                            <span><?= h($invoice->operation_center->name) ?></span>
                        </div>
                        <?php endif; ?>
                    </td>

                    <!-- Emisión -->
                    <td class="mono sgi-fg-muted" style="font-size:12px;white-space:nowrap;">
                        <?= $invoice->issue_date?->format('d/m/Y') ?: '—' ?>
                    </td>

                    <!-- Vencimiento -->
                    <td style="white-space:nowrap;">
                        <span class="mono <?= $row->isOverdue ? 'sgi-fg-danger fw-bold' : 'sgi-fg-muted' ?>" style="font-size:12px;">
                            <?= $invoice->due_date?->format('d/m/Y') ?: '—' ?>
                        </span>
                        <?php if ($row->isOverdue): ?>
                            <i class="bi bi-exclamation-circle-fill sgi-fg-danger ms-1" style="font-size:.7rem;"
                               title="Vencida" aria-hidden="true"></i>
                        <?php endif; ?>
                    </td>

                    <!-- Valor -->
                    <td class="text-end mono" style="white-space:nowrap;font-weight:700;font-size:13.5px;color:<?= $row->isPaid ? 'var(--primary-color)' : 'var(--text-default)' ?>;">
                        $ <?= number_format((float)$invoice->amount, 0, ',', '.') ?>
                    </td>

                    <!-- Estado pipeline + mini-bar + badges secundarios -->
                    <td>
                        <?php if ($stageIdx >= 0): ?>
                        <div class="sgi-pipeline-mini mb-1" aria-hidden="true">
                            <?php for ($i = 0, $n = count($steps); $i < $n; $i++): ?>
                                <div class="<?= $i <= $stageIdx ? 'on' : '' ?>"></div>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap align-items-center gap-1">
                            <?php if ($row->isRejected): ?>
                                <span class="pill pill-danger-soft"><?= h(strtoupper($row->statusLabel)) ?></span>
                                <span class="pill pill-danger-soft">RECHAZADA</span>
                            <?php else: ?>
                                <span class="pill <?= h($pillClass) ?>">
                                    <?php if ($row->isPaid): ?><i class="bi bi-check2" aria-hidden="true"></i><?php endif; ?>
                                    <?= h(strtoupper($row->statusLabel)) ?>
                                </span>
                                <?php if (isset($approvalSummaries[$invoice->id]) && $approvalSummaries[$invoice->id]['total'] > 0):
                                    $s = $approvalSummaries[$invoice->id];
                                ?>
                                    <?php if ($s['rejected'] > 0): ?>
                                        <span class="pill pill-danger-soft">RECHAZADA</span>
                                    <?php elseif ($s['approved'] === $s['total']): ?>
                                        <span class="pill pill-primary-soft">APROBADA</span>
                                    <?php else: ?>
                                        <span class="pill pill-muted"><?= $s['approved'] ?>/<?= $s['total'] ?> APROBADOS</span>
                                    <?php endif; ?>
                                <?php elseif ($row->isApproved): ?>
                                    <span class="pill pill-primary-soft">APROBADA</span>
                                <?php endif; ?>
                                <?php if ($row->isPartialPay): ?>
                                    <span class="pill pill-warning-soft">PAGO PARCIAL</span>
                                <?php endif; ?>
                                <?php if ($row->isReadyForPay): ?>
                                    <span class="pill <?= h($readyForPaymentPills[$invoice->ready_for_payment] ?? 'pill-muted') ?>"><?= h(strtoupper($invoice->ready_for_payment)) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>

                    <!-- Observaciones sin leer / chevron -->
                    <?php $unread = (int)($invoice->unread_observations ?? 0); ?>
                    <td class="text-end" style="white-space:nowrap;">
                        <?php if ($unread > 0): ?>
                            <span class="pill pill-danger-soft"
                                  title="<?= $unread ?> observación<?= $unread > 1 ? 'es' : '' ?> sin leer">
                                <i class="bi bi-chat-left-text-fill" style="font-size:.65rem;" aria-hidden="true"></i><?= $unread ?>
                            </span>
                        <?php else: ?>
                            <i class="bi bi-chevron-right sgi-fg-faint" aria-hidden="true"></i>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if ($rowCount === 0): ?>
                <tr>
                    <td colspan="7">
                        <div class="sgi-doc-empty">
                            <i class="bi bi-inbox sgi-doc-empty-icon" aria-hidden="true"></i>
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

<?= $this->element('excel_wizard/modals', [
    'module' => 'Invoices',
    'entityName' => 'Facturas',
    'downloadSlug' => 'facturas',
    'importable' => false,
]) ?>

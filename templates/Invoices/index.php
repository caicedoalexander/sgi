<?php
/**
 * Listado de Facturas — Sistema de Diseño v2 (lista-facturas spec).
 *
 * Vista única reutilizada por las acciones `index`, `all`, `rejected` y `overdue`
 * del InvoicesController. Replica el rediseño de `lista-facturas.jsx`:
 *   · Header: título + meta (período · documentos · total) + acciones a la derecha.
 *   · Search bar + botón "Filtros" (collapsible con filtros avanzados).
 *   · Chips por estado del pipeline (.chip / .chip.is-active).
 *   · Tabla densa con grid 7-col, .pipeline-mini horizontal y pills soft.
 *   · Footer con paginación.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Invoice> $invoices
 * @var string $roleName
 * @var string[] $visibleStatuses
 * @var array<int,array{total:int,approved:int,rejected:int}> $approvalSummaries
 * @var \Cake\ORM\ResultSet $providers
 * @var \Cake\ORM\ResultSet $operationCenters
 * @var \Cake\ORM\ResultSet $expenseTypes
 */

use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

/* ─────────── Contexto de la vista ─────────── */
$action = $this->request->getParam('action');
$isAllView      = $action === 'all';
$isRejectedView = $action === 'rejected';
$isOverdueView  = $action === 'overdue';

$pageTitle = match (true) {
    $isRejectedView => 'Facturas Rechazadas',
    $isOverdueView  => 'Facturas Vencidas',
    $isAllView      => 'Todas las Facturas',
    default         => 'Mis Facturas',
};
$this->assign('title', $pageTitle);

$query           = $this->request->getQueryParams();
$activeStatus    = (string)$this->request->getQuery('pipeline_status', '');
$searchValue     = (string)$this->request->getQuery('search', '');
$activeFilters   = array_filter(
    $query,
    fn ($v, $k) => $k !== 'page' && $v !== '' && $v !== null,
    ARRAY_FILTER_USE_BOTH
);
$filterCount     = count($activeFilters);
$pipelineOptions = InvoiceConstants::STATUS_LABELS;

/* Materializar el ResultSet para sumar y luego iterar. */
$invoicesArr = is_array($invoices) ? $invoices : iterator_to_array($invoices, false);
$pageTotal   = 0.0;
foreach ($invoicesArr as $inv) {
    $pageTotal += (float)$inv->amount;
}
$totalCount = $this->Paginator->counter('{{count}}');

/* Meta-línea: período actual en español. */
$mesesEs = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$now         = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

/* ─────────── Chips por estado (no aplica para rejected/overdue) ─────────── */
$tabAction    = $isRejectedView ? 'rejected' : ($isOverdueView ? 'overdue' : ($isAllView ? 'all' : 'index'));
$baseQuery    = array_diff_key($query, ['pipeline_status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($tabAction, $baseQuery): array {
    $params = ['action' => $tabAction];
    if ($status !== null) {
        $params['?'] = $baseQuery + ['pipeline_status' => $status];
    } elseif (!empty($baseQuery)) {
        $params['?'] = $baseQuery;
    }
    return $params;
};
/* [slug, label, color-css] */
$tabs = [
    [null,                                       'Todas',         'var(--primary-color)'],
    [InvoiceConstants::STATUS_APROBACION,        'En aprobación', 'var(--warning-text)'],
    [InvoiceConstants::STATUS_CONTABILIDAD,      'Contabilidad',  'var(--secondary-color)'],
    [InvoiceConstants::STATUS_TESORERIA,         'Tesorería',     'var(--accent-color)'],
    [InvoiceConstants::STATUS_AUTORIZACION_PAGO, 'Autorización',  'var(--warning-text)'],
    [InvoiceConstants::STATUS_PAGADA,            'Pagadas',       'var(--primary-color)'],
];

/* Pills para ready_for_payment. */
$readyForPaymentPills = [
    InvoiceConstants::READY_FOR_PAYMENT_SI          => 'pill-primary-soft',
    InvoiceConstants::READY_FOR_PAYMENT_PRIORITARIO => 'pill-danger-soft',
    InvoiceConstants::READY_FOR_PAYMENT_PSE         => 'pill-dark',
];
?>

<?php /* ════════════════════════ HEADER ════════════════════════ */ ?>
<div class="d-flex justify-content-between align-items-start" style="padding:4px 0 16px;">
    <div>
        <div style="font-size:22px;font-weight:700;color:var(--text-strong);letter-spacing:-0.2px;">
            <?= h($pageTitle) ?>
        </div>
        <div style="font-size:12px;color:var(--text-faint);margin-top:4px;">
            Período: <?= h($periodLabel) ?> ·
            <span style="color:var(--text-muted);"><?= $totalCount ?> documentos</span> ·
            <span class="mono" style="color:var(--text-muted);">$ <?= number_format($pageTotal, 0, ',', '.') ?></span>
        </div>
    </div>
    <div class="d-flex" style="gap:8px;">
        <?php if (!empty($userPermissions['dian_crosschecks']['can_create'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-arrow-left-right" aria-hidden="true"></i><span>Cruce</span>',
                ['controller' => 'DianCrosschecks', 'action' => 'add'],
                ['class' => 'btn btn-default', 'escape' => false]
            ) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['dian_crosschecks']['can_view'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-arrow-clockwise" aria-hidden="true"></i><span>Cruces</span>',
                ['controller' => 'DianCrosschecks', 'action' => 'index'],
                ['class' => 'btn btn-default', 'escape' => false]
            ) ?>
        <?php endif; ?>
        <?= $this->element('excel_wizard/buttons', [
            'module'     => 'Invoices',
            'importable' => false,
            'canCreate'  => !empty($userPermissions['invoices']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['invoices']['can_create'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg" aria-hidden="true"></i><span>Nueva Factura</span>',
                ['action' => 'add'],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>
</div>

<?php /* ════════════════════════ SEARCH + FILTROS ════════════════════════ */ ?>
<?= $this->Form->create(null, [
    'type'         => 'get',
    'url'          => ['action' => $tabAction],
    'valueSources' => ['query'],
]) ?>
<div class="d-flex align-items-stretch" style="gap:8px;margin-bottom:14px;">
    <label class="input flex-grow-1" style="margin:0;">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="text" name="search"
               value="<?= h($searchValue) ?>"
               placeholder="Buscar por número, orden de compra, detalle o proveedor…"
               aria-label="Buscar facturas">
        <?php if ($searchValue !== ''): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x" aria-hidden="true"></i>',
                ['action' => $tabAction],
                [
                    'escape' => false,
                    'style'  => 'background:transparent;border:0;color:var(--text-faint);padding:4px;display:inline-flex;',
                    'title'  => 'Limpiar búsqueda',
                ]
            ) ?>
        <?php endif; ?>
    </label>

    <button type="button" class="btn btn-default"
            data-bs-toggle="collapse" data-bs-target="#invoiceFilters"
            aria-expanded="<?= $filterCount > 0 ? 'true' : 'false' ?>"
            aria-label="Filtros avanzados">
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span>Filtros<?php if ($filterCount > 0): ?> · <span style="color:var(--primary-color);font-weight:700;"><?= $filterCount ?></span><?php endif; ?></span>
    </button>

    <?php if ($filterCount > 0): ?>
        <?= $this->Html->link(
            '<i class="bi bi-x-lg" aria-hidden="true"></i><span>Limpiar</span>',
            ['action' => $tabAction],
            ['class' => 'btn btn-ghost', 'escape' => false, 'style' => 'color:var(--danger-color);']
        ) ?>
    <?php endif; ?>
</div>

<div class="collapse <?= $filterCount > 0 ? 'show' : '' ?>" id="invoiceFilters" style="margin-bottom:14px;">
    <div class="sgi-card compact">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="input-label" for="filter-provider">Proveedor</label>
                <?= $this->Form->select('provider_id', $providers, [
                    'empty' => 'Todos',
                    'class' => 'form-select form-select-sm',
                    'value' => $this->request->getQuery('provider_id', ''),
                    'id'    => 'filter-provider',
                ]) ?>
            </div>
            <div class="col-md-2">
                <label class="input-label" for="filter-opcenter">Centro Op.</label>
                <?= $this->Form->select('operation_center_id', $operationCenters, [
                    'empty' => 'Todos',
                    'class' => 'form-select form-select-sm',
                    'value' => $this->request->getQuery('operation_center_id', ''),
                    'id'    => 'filter-opcenter',
                ]) ?>
            </div>
            <div class="col-md-2">
                <label class="input-label" for="filter-expense">Tipo Gasto</label>
                <?= $this->Form->select('expense_type_id', $expenseTypes, [
                    'empty' => 'Todos',
                    'class' => 'form-select form-select-sm',
                    'value' => $this->request->getQuery('expense_type_id', ''),
                    'id'    => 'filter-expense',
                ]) ?>
            </div>
            <div class="col-md-2">
                <label class="input-label" for="filter-status">Estado</label>
                <?= $this->Form->select('pipeline_status', $pipelineOptions, [
                    'empty' => 'Todos',
                    'class' => 'form-select form-select-sm',
                    'value' => $activeStatus,
                    'id'    => 'filter-status',
                ]) ?>
            </div>
            <div class="col-md-3">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="input-label" for="filter-date-from">Desde</label>
                        <input type="text" name="date_from" id="filter-date-from"
                               class="form-control form-control-sm flatpickr-date"
                               value="<?= h($this->request->getQuery('date_from', '')) ?>"
                               placeholder="Fecha desde">
                    </div>
                    <div class="col-6">
                        <label class="input-label" for="filter-date-to">Hasta</label>
                        <input type="text" name="date_to" id="filter-date-to"
                               class="form-control form-control-sm flatpickr-date"
                               value="<?= h($this->request->getQuery('date_to', '')) ?>"
                               placeholder="Fecha hasta">
                    </div>
                </div>
            </div>
            <div class="col-12 d-flex justify-content-end" style="margin-top:6px;">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check2" aria-hidden="true"></i><span>Aplicar filtros</span>
                </button>
            </div>
        </div>
    </div>
</div>
<?= $this->Form->end() ?>

<?php /* ════════════════════════ CHIPS POR ESTADO ════════════════════════ */ ?>
<?php if (!$isRejectedView && !$isOverdueView): ?>
<div class="d-flex" style="gap:4px;margin-bottom:14px;" role="tablist" aria-label="Filtrar por estado">
    <?php foreach ($tabs as [$status, $label, $color]):
        $isActive = ($activeStatus === ($status ?? ''));
    ?>
        <?= $this->Html->link(
            ($isActive ? '<span class="dot" style="background:' . $color . ';"></span>' : '') . h($label),
            $tabUrl($status),
            [
                'class'         => 'chip' . ($isActive ? ' is-active' : ''),
                'escape'        => false,
                'role'          => 'tab',
                'aria-selected' => $isActive ? 'true' : 'false',
                'style'         => $isActive ? 'color:' . $color . ';' : '',
            ]
        ) ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php /* ════════════════════════ TABLA DE FACTURAS ════════════════════════ */ ?>
<?php
/* Grid 7-col compartido entre header y filas (alineado al spec lista-facturas). */
$gridStyle = 'display:grid;grid-template-columns:1.3fr 2.4fr 1fr 1fr 1.1fr 1.7fr 36px;gap:14px;align-items:center;';
?>
<div class="sgi-card" style="padding:0;">
    <?php /* — Header de columnas — */ ?>
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>Factura</span>
        <span>Proveedor</span>
        <span><?= $this->Paginator->sort('issue_date', 'Emisión') ?></span>
        <span><?= $this->Paginator->sort('due_date', 'Vencimiento') ?></span>
        <span style="text-align:right;"><?= $this->Paginator->sort('amount', 'Valor') ?></span>
        <span>Estado · Pipeline</span>
        <span aria-hidden="true"></span>
    </div>

    <?php /* — Filas — */ ?>
    <?php
    $rowCount = 0;
    $today    = new \DateTimeImmutable('today');
    foreach ($invoicesArr as $i => $invoice):
        $rowCount++;
        $row  = InvoicePresentation::forRow($invoice, $today);
        $href = $this->Url->build(['action' => $isAllView ? 'view' : 'edit', $invoice->id]);
    ?>
        <a href="<?= h($href) ?>" role="row" class="row-fact"
           style="<?= $gridStyle ?>padding:14px 18px;">

            <?php /* 1. Factura: código + tipo */ ?>
            <div style="min-width:0;">
                <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);">
                    <?= h($invoice->invoice_number ?: '—') ?>
                </div>
                <div style="font-size:9.5px;color:var(--text-faint);letter-spacing:0.5px;font-weight:600;margin-top:2px;text-transform:uppercase;">
                    <?= h($invoice->document_type) ?>
                </div>
            </div>

            <?php /* 2. Proveedor + centro */ ?>
            <div style="min-width:0;">
                <div style="font-size:12.5px;font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= $invoice->hasValue('provider')
                        ? h($invoice->provider->name)
                        : '<span style="color:var(--text-faint);">—</span>' ?>
                </div>
                <?php if ($invoice->hasValue('operation_center')): ?>
                    <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;display:inline-flex;align-items:center;gap:4px;">
                        <i class="bi bi-geo-alt" style="font-size:10px;" aria-hidden="true"></i>
                        <span><?= h($invoice->operation_center->name) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php /* 3. Emisión */ ?>
            <div class="mono" style="font-size:12px;color:var(--text-default);">
                <?= $invoice->issue_date?->format('d/m/Y') ?: '—' ?>
            </div>

            <?php /* 4. Vencimiento */ ?>
            <div class="mono" style="font-size:12px;<?= $row->isOverdue
                ? 'color:var(--danger-color);font-weight:700;'
                : 'color:var(--text-default);' ?>">
                <?= $invoice->due_date?->format('d/m/Y') ?: '—' ?>
            </div>

            <?php /* 5. Valor */ ?>
            <div class="mono" style="text-align:right;font-size:13.5px;font-weight:700;<?= $row->isPaid
                ? 'color:var(--primary-color);'
                : 'color:var(--text-default);' ?>">
                $ <?= number_format((float)$invoice->amount, 0, ',', '.') ?>
            </div>

            <?php /* 6. Estado · Pipeline */ ?>
            <div style="min-width:0;">
                <?php if ($row->stageIdx >= 0): ?>
                    <div class="pipeline-mini <?= h($row->pipelineVariant) ?>" aria-hidden="true" style="margin-bottom:5px;max-width:100%;">
                        <?php for ($s = 0, $n = count($row->pipelineSteps); $s < $n; $s++): ?>
                            <div class="<?= $s <= $row->stageIdx ? 'on' : '' ?>"></div>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    <?php if ($row->isRejected): ?>
                        <span class="pill pill-danger-soft pill-sm">RECHAZADA</span>
                    <?php else: ?>
                        <span class="pill <?= h($row->pillClass) ?> pill-sm">
                            <?php if ($row->isPaid): ?><i class="bi bi-check" style="font-size:9px;" aria-hidden="true"></i><?php endif; ?>
                            <?= h(strtoupper($row->statusLabel)) ?>
                        </span>
                        <?php if (isset($approvalSummaries[$invoice->id]) && $approvalSummaries[$invoice->id]['total'] > 0):
                            $s = $approvalSummaries[$invoice->id]; ?>
                            <?php if ($s['rejected'] > 0): ?>
                                <span class="pill pill-danger-soft pill-sm">RECHAZADA</span>
                            <?php elseif ($s['approved'] === $s['total']): ?>
                                <span class="pill pill-primary-soft pill-sm">APROBADA</span>
                            <?php else: ?>
                                <span class="pill pill-muted pill-sm"><?= $s['approved'] ?>/<?= $s['total'] ?></span>
                            <?php endif; ?>
                        <?php elseif ($row->isApproved): ?>
                            <span class="pill pill-primary-soft pill-sm">APROBADA</span>
                        <?php endif; ?>
                        <?php if ($row->isPartialPay): ?>
                            <span class="pill pill-warning-soft pill-sm">PARCIAL</span>
                        <?php endif; ?>
                        <?php if ($row->isReadyForPay && !$row->isPaid): ?>
                            <span class="pill <?= h($readyForPaymentPills[$invoice->ready_for_payment] ?? 'pill-muted') ?> pill-sm">
                                <?= h(strtoupper((string)$invoice->ready_for_payment)) ?>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php /* 7. Chevron */ ?>
            <div style="display:flex;justify-content:flex-end;align-items:center;color:var(--text-faint);">
                <?php $unread = (int)($invoice->unread_observations ?? 0); ?>
                <?php if ($unread > 0): ?>
                    <span class="pill pill-danger-soft pill-sm"
                          title="<?= $unread ?> observación<?= $unread > 1 ? 'es' : '' ?> sin leer">
                        <i class="bi bi-chat-left-text-fill" style="font-size:9px;" aria-hidden="true"></i><?= $unread ?>
                    </span>
                <?php else: ?>
                    <i class="bi bi-chevron-right" style="font-size:14px;" aria-hidden="true"></i>
                <?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>

    <?php /* — Empty state — */ ?>
    <?php if ($rowCount === 0): ?>
        <div class="empty-state" style="padding:48px 16px;">
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-inbox" aria-hidden="true"></i>
            </div>
            <div class="es-title">Sin facturas en este filtro</div>
            <div class="es-msg">Cambia el filtro o crea una nueva factura.</div>
            <?php if (!empty($userPermissions['invoices']['can_create'])): ?>
                <?= $this->Html->link(
                    '<i class="bi bi-plus-lg" aria-hidden="true"></i><span>Nueva Factura</span>',
                    ['action' => 'add'],
                    ['class' => 'btn btn-primary btn-sm', 'escape' => false]
                ) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php /* — Footer / paginación — */ ?>
    <?php if ($rowCount > 0): ?>
        <?= $this->element('pagination') ?>
    <?php endif; ?>
</div>

<?= $this->element('excel_wizard/modals', [
    'module'       => 'Invoices',
    'entityName'   => 'Facturas',
    'downloadSlug' => 'facturas',
    'importable'   => false,
]) ?>

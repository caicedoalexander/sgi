<?php
/**
 * Listado de Reintegros — Sistema de Diseño v2.
 *
 * Estructura espejo de templates/Invoices/index.php: header con meta, search +
 * filtros colapsables, chips por estado, tabla con grid CSS inline, .pipeline-mini
 * y pills soft, empty state y paginación.
 *
 * @var \App\View\AppView $this
 * @var iterable $records
 * @var array $visibleStatuses
 */

use App\Constants\RefundConstants;
use App\View\Presentation\RefundPresentation;

$action = $this->request->getParam('action');
$pageTitles = [
    'all'     => 'Todos los Reintegros',
    'pending' => 'Pendientes',
];
$pageTitle = $pageTitles[$action] ?? 'Reintegros';
$this->assign('title', $pageTitle);

$statusLabels  = RefundConstants::STATUS_LABELS;

$query        = $this->request->getQueryParams();
$activeStatus = (string)($query['status'] ?? '');
$searchValue  = (string)($query['code'] ?? '');
$activeFilters = array_filter(
    $query,
    fn ($v, $k) => $k !== 'page' && $v !== '' && $v !== null,
    ARRAY_FILTER_USE_BOTH
);
$filterCount = count($activeFilters);

// Materializar el ResultSet para sumar y luego iterar.
$recordsArr = is_array($records) ? $records : iterator_to_array($records, false);
$pageTotal  = 0.0;
foreach ($recordsArr as $r) {
    $pageTotal += (float)$r->total_amount;
}
$totalCount = $this->Paginator->counter('{{count}}');

$mesesEs = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$now         = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

// Chips por estado.
$baseQuery = array_diff_key($query, ['status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($action, $baseQuery): array {
    $params = ['action' => $action ?: 'index'];
    if ($status !== null) {
        $params['?'] = $baseQuery + ['status' => $status];
    } elseif (!empty($baseQuery)) {
        $params['?'] = $baseQuery;
    }
    return $params;
};
/* [slug, label, color-css] */
$tabs = [
    [null,                                      'Todos',        'var(--primary-color)'],
    [RefundConstants::STATUS_AGRUPACION,        'Agrupación',   'var(--text-muted)'],
    [RefundConstants::STATUS_CONTABILIDAD,      'Contabilidad', 'var(--secondary-color)'],
    [RefundConstants::STATUS_TESORERIA,         'Tesorería',    'var(--accent-color)'],
    [RefundConstants::STATUS_AUTORIZACION_PAGO, 'Autorización', 'var(--warning-text)'],
    [RefundConstants::STATUS_PAGADA,            'Pagados',      'var(--primary-color)'],
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
            <span style="color:var(--text-muted);"><?= $totalCount ?> reintegros</span> ·
            <span class="mono" style="color:var(--text-muted);">$ <?= number_format($pageTotal, 0, ',', '.') ?></span>
        </div>
    </div>
    <div class="d-flex" style="gap:8px;">
        <?php if (!empty($userPermissions['refunds']['can_create'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg" aria-hidden="true"></i><span>Nuevo Reintegro</span>',
                ['action' => 'add'],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>
</div>

<?php /* ════════════════════════ SEARCH + FILTROS ════════════════════════ */ ?>
<?= $this->Form->create(null, [
    'type'         => 'get',
    'url'          => ['action' => $action ?: 'index'],
    'valueSources' => ['query'],
]) ?>
<div class="d-flex align-items-stretch" style="gap:8px;margin-bottom:14px;">
    <label class="input flex-grow-1" style="margin:0;">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="text" name="code"
               value="<?= h($searchValue) ?>"
               placeholder="Buscar por código (REI-2026-…)"
               aria-label="Buscar reintegros">
        <?php if ($searchValue !== ''): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x" aria-hidden="true"></i>',
                ['action' => $action ?: 'index'],
                [
                    'escape' => false,
                    'style'  => 'background:transparent;border:0;color:var(--text-faint);padding:4px;display:inline-flex;',
                    'title'  => 'Limpiar búsqueda',
                ]
            ) ?>
        <?php endif; ?>
    </label>

    <button type="button" class="btn btn-default"
            data-bs-toggle="collapse" data-bs-target="#refundFilters"
            aria-expanded="<?= $filterCount > 0 ? 'true' : 'false' ?>"
            aria-label="Filtros avanzados">
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span>Filtros<?php if ($filterCount > 0): ?> · <span style="color:var(--primary-color);font-weight:700;"><?= $filterCount ?></span><?php endif; ?></span>
    </button>

    <?php if ($filterCount > 0): ?>
        <?= $this->Html->link(
            '<i class="bi bi-x-lg" aria-hidden="true"></i><span>Limpiar</span>',
            ['action' => $action ?: 'index'],
            ['class' => 'btn btn-ghost', 'escape' => false, 'style' => 'color:var(--danger-color);']
        ) ?>
    <?php endif; ?>
</div>

<div class="collapse <?= $filterCount > 0 ? 'show' : '' ?>" id="refundFilters" style="margin-bottom:14px;">
    <div class="sgi-card compact">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="input-label" for="filter-status">Estado</label>
                <?= $this->Form->select('status', $statusLabels, [
                    'empty' => 'Todos',
                    'class' => 'form-select form-select-sm',
                    'value' => $activeStatus,
                    'id'    => 'filter-status',
                ]) ?>
            </div>
            <div class="col-md-3">
                <label class="input-label" for="filter-date-from">Desde</label>
                <input type="text" name="date_from" id="filter-date-from"
                       class="form-control form-control-sm flatpickr-date"
                       value="<?= h($this->request->getQuery('date_from', '')) ?>"
                       placeholder="Fecha desde">
            </div>
            <div class="col-md-3">
                <label class="input-label" for="filter-date-to">Hasta</label>
                <input type="text" name="date_to" id="filter-date-to"
                       class="form-control form-control-sm flatpickr-date"
                       value="<?= h($this->request->getQuery('date_to', '')) ?>"
                       placeholder="Fecha hasta">
            </div>
            <div class="col-md-3 d-flex align-items-end justify-content-end">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check2" aria-hidden="true"></i><span>Aplicar filtros</span>
                </button>
            </div>
        </div>
    </div>
</div>
<?= $this->Form->end() ?>

<?php /* ════════════════════════ CHIPS POR ESTADO ════════════════════════ */ ?>
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

<?php /* ════════════════════════ TABLA DE REINTEGROS ════════════════════════ */ ?>
<?php
/* Grid 7-col compartido entre header y filas. */
$gridStyle = 'display:grid;grid-template-columns:1.3fr 2fr 1.1fr 0.8fr 1fr 1.7fr 36px;gap:14px;align-items:center;';
?>
<div class="sgi-card" style="padding:0;">
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>Código</span>
        <span>Beneficiario</span>
        <span style="text-align:right;">Total</span>
        <span style="text-align:center;"># Facturas</span>
        <span>Fecha</span>
        <span>Estado · Pipeline</span>
        <span aria-hidden="true"></span>
    </div>

    <?php
    $rowCount = 0;
    foreach ($recordsArr as $i => $record):
        $rowCount++;
        $row  = RefundPresentation::forRow($record);
        $href = $this->Url->build(['action' => 'edit', $record->id]);
    ?>
        <a href="<?= h($href) ?>" role="row" class="row-fact"
           style="<?= $gridStyle ?>padding:14px 18px;">

            <?php /* 1. Código + tipo */ ?>
            <div style="min-width:0;">
                <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);">
                    <?= h($record->code ?: '—') ?>
                </div>
                <div style="font-size:9.5px;color:var(--text-faint);letter-spacing:0.5px;font-weight:600;margin-top:2px;text-transform:uppercase;">
                    Reintegro
                </div>
            </div>

            <?php /* 2. Beneficiario + creador */ ?>
            <div style="min-width:0;">
                <div style="font-size:12.5px;font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= $row->beneficiaryName
                        ? h($row->beneficiaryName)
                        : '<span style="color:var(--text-faint);">—</span>' ?>
                </div>
                <?php if ($record->hasValue('created_by_user')): ?>
                    <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;display:inline-flex;align-items:center;gap:4px;">
                        <i class="bi bi-person" style="font-size:10px;" aria-hidden="true"></i>
                        <span><?= h($record->created_by_user->full_name) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php /* 3. Total */ ?>
            <div class="mono" style="text-align:right;font-size:13.5px;font-weight:700;<?= $row->isPaid ? 'color:var(--primary-color);' : 'color:var(--text-default);' ?>">
                $ <?= number_format((float)$record->total_amount, 0, ',', '.') ?>
            </div>

            <?php /* 4. # Facturas */ ?>
            <div class="mono" style="text-align:center;font-size:12px;color:var(--text-muted);">
                <?= $row->invoiceCount ?>
            </div>

            <?php /* 5. Fecha */ ?>
            <div class="mono" style="font-size:12px;color:var(--text-default);">
                <?= $record->created?->format('d/m/Y') ?: '—' ?>
            </div>

            <?php /* 6. Estado · Pipeline */ ?>
            <div style="min-width:0;">
                <?php if ($row->stageIdx >= 0): ?>
                    <div class="pipeline-mini" aria-hidden="true" style="margin-bottom:5px;max-width:100%;">
                        <?php for ($s = 0; $s < $row->pipelineLength; $s++): ?>
                            <div class="<?= $s <= $row->stageIdx ? 'on' : '' ?>"></div>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    <span class="pill <?= h($row->statusBadgeClass) ?> pill-sm">
                        <?php if ($row->isPaid): ?><i class="bi bi-check" style="font-size:9px;" aria-hidden="true"></i><?php endif; ?>
                        <?= h(strtoupper($row->statusLabel)) ?>
                    </span>
                </div>
            </div>

            <?php /* 7. Chevron */ ?>
            <div style="display:flex;justify-content:flex-end;align-items:center;color:var(--text-faint);">
                <i class="bi bi-chevron-right" style="font-size:14px;" aria-hidden="true"></i>
            </div>
        </a>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
        <div class="empty-state" style="padding:48px 16px;">
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-inbox" aria-hidden="true"></i>
            </div>
            <div class="es-title">No hay reintegros en este filtro</div>
            <div class="es-msg">Cambia el filtro o crea un nuevo reintegro.</div>
        </div>
    <?php endif; ?>

    <?php if ($rowCount > 0): ?>
        <?= $this->element('pagination') ?>
    <?php endif; ?>
</div>

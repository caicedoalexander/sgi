<?php
/**
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

$statusBadge  = RefundPresentation::STATUS_BADGES;
$statusLabels = RefundConstants::STATUS_LABELS;
$pipelineSteps = RefundConstants::STATUSES;

$query = $this->request->getQueryParams();
$hasFilters = !empty(array_filter($query, fn($v) => $v !== '' && $v !== null));
$activeStatus = (string)($query['status'] ?? '');

// Materializar el ResultSet.
$recordsArr = is_array($records) ? $records : iterator_to_array($records, false);
$pageTotal = 0.0;
foreach ($recordsArr as $r) { $pageTotal += (float)$r->total_amount; }

$mesesEs = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$now = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

$baseQuery = array_diff_key($query, ['status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($action, $baseQuery) {
    $params = ['action' => $action ?: 'index'];
    if ($status !== null) { $params['?'] = $baseQuery + ['status' => $status]; }
    elseif (!empty($baseQuery)) { $params['?'] = $baseQuery; }
    return $params;
};
$tabs = [
    [null,                                         'Todos'],
    [RefundConstants::STATUS_AGRUPACION,           'Agrupación'],
    [RefundConstants::STATUS_CONTABILIDAD,         'Contabilidad'],
    [RefundConstants::STATUS_TESORERIA,            'Tesorería'],
    [RefundConstants::STATUS_AUTORIZACION_PAGO,    'Autorización'],
    [RefundConstants::STATUS_PAGADA,               'Pagados'],
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div>
        <span class="sgi-page-title"><?= $pageTitle ?></span>
        <div class="sgi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            Período: <?= h($periodLabel) ?> ·
            <span class="sgi-fg-muted"><?= $this->Paginator->counter('{{count}} reintegros') ?></span> ·
            <span class="sgi-fg-muted mono">$ <?= number_format($pageTotal, 0, ',', '.') ?></span>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['refunds']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Reintegro',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filters -->
<?= $this->Form->create(null, ['type' => 'get', 'url' => ['action' => $action ?: 'index'], 'valueSources' => ['query']]) ?>
<div class="d-flex gap-2 align-items-stretch mb-3">
    <div class="sgi-search-bar flex-grow-1">
        <div class="sgi-input-icon w-100">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="text" name="code"
                   class="form-control"
                   placeholder="Buscar por código (REI-2026-...)"
                   value="<?= h($this->request->getQuery('code', '')) ?>"
                   aria-label="Buscar reintegros">
        </div>
    </div>
    <button type="button" class="btn btn-ghost-card sgi-filters-trigger"
            data-bs-toggle="collapse" data-bs-target="#refundFilters" aria-label="Filtros avanzados">
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span>Filtros<?php if ($hasFilters): ?> · <span class="sgi-fg-primary"><?= count(array_filter($query, fn($v) => $v !== '' && $v !== null)) ?></span><?php endif; ?></span>
    </button>
    <?php if ($hasFilters): ?>
        <?= $this->Html->link(
            '<i class="bi bi-x-lg" aria-hidden="true"></i> Limpiar',
            ['action' => $action ?: 'index'],
            ['class' => 'btn btn-ghost-card sgi-fg-danger', 'escape' => false]
        ) ?>
    <?php endif; ?>
</div>

<div class="collapse <?= $hasFilters ? 'show' : '' ?> mb-3" id="refundFilters">
    <div class="card p-3">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="sgi-filter-label" for="filter-status">Estado</label>
                <?= $this->Form->select('status', $statusLabels, [
                    'empty' => 'Todos',
                    'class' => 'form-select form-select-sm',
                    'value' => $this->request->getQuery('status', ''),
                    'id'    => 'filter-status',
                ]) ?>
            </div>
            <div class="col-md-3">
                <label class="sgi-filter-label" for="filter-date-from">Desde</label>
                <input type="text" name="date_from" id="filter-date-from"
                       class="form-control form-control-sm flatpickr-date"
                       value="<?= h($this->request->getQuery('date_from', '')) ?>"
                       placeholder="Fecha desde">
            </div>
            <div class="col-md-3">
                <label class="sgi-filter-label" for="filter-date-to">Hasta</label>
                <input type="text" name="date_to" id="filter-date-to"
                       class="form-control form-control-sm flatpickr-date"
                       value="<?= h($this->request->getQuery('date_to', '')) ?>"
                       placeholder="Fecha hasta">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-search me-1" aria-hidden="true"></i>Aplicar filtros
                </button>
            </div>
        </div>
    </div>
</div>
<?= $this->Form->end() ?>

<!-- Tabs por estado de pipeline -->
<div class="sgi-status-tabs mb-3" role="tablist" aria-label="Filtrar por estado">
    <?php foreach ($tabs as [$status, $label]):
        $isActive = ($activeStatus === ($status ?? ''));
        $color = match ($status) {
            RefundConstants::STATUS_AGRUPACION        => 'var(--text-muted)',
            RefundConstants::STATUS_CONTABILIDAD      => 'var(--primary-color)',
            RefundConstants::STATUS_TESORERIA         => 'var(--info-text)',
            RefundConstants::STATUS_AUTORIZACION_PAGO => 'var(--info-text)',
            RefundConstants::STATUS_PAGADA            => 'var(--primary-color)',
            default                                   => 'var(--primary-color)',
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

<?php
// Columnas: Código | Beneficiario | Total | # Facturas | Fecha | Pipeline/Estado | chevron
$gridCols = '1.2fr 1.8fr 1.1fr 0.7fr 1fr 1.7fr 36px';
?>
<div class="card">
    <div class="sgi-row-fact-grid sgi-row-fact-head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Código</span>
        <span>Beneficiario</span>
        <span class="text-end">Total</span>
        <span class="text-center"># Facturas</span>
        <span>Fecha</span>
        <span>Estado · Pipeline</span>
        <span aria-hidden="true"></span>
    </div>

    <?php
    $rowCount = 0;
    foreach ($recordsArr as $record):
        $rowCount++;
        $stageIdx = array_search($record->status, $pipelineSteps, true);
        if ($stageIdx === false) { $stageIdx = -1; }
        $pillClass = $statusBadge[$record->status] ?? 'pill-muted';
        $invoiceCount = count($record->invoices ?? []);
        $isPaid = $record->status === RefundConstants::STATUS_PAGADA;
        $beneficiary = $record->getBeneficiaryName() ?? null;
    ?>
        <a class="sgi-row-fact sgi-row-fact-grid"
           style="grid-template-columns:<?= $gridCols ?>;"
           href="<?= $this->Url->build(['action' => 'edit', $record->id]) ?>"
           role="row">

            <!-- Código -->
            <div>
                <div class="sgi-row-fact-id"><?= h($record->code ?: '—') ?></div>
                <div class="sgi-row-fact-type">Reintegro</div>
            </div>

            <!-- Beneficiario -->
            <div style="min-width:0;">
                <div class="sgi-row-fact-provider">
                    <?= $beneficiary ? h($beneficiary) : '<span class="sgi-fg-faint">—</span>' ?>
                </div>
                <?php if ($record->hasValue('created_by_user')): ?>
                <div class="sgi-row-fact-center">
                    <i class="bi bi-person" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
                    <span><?= h($record->created_by_user->full_name) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Total -->
            <div class="sgi-row-fact-amount<?= $isPaid ? ' is-paid' : '' ?>">
                $ <?= number_format((float)$record->total_amount, 0, ',', '.') ?>
            </div>

            <!-- # Facturas -->
            <div class="sgi-row-fact-date sgi-fg-muted text-center">
                <?= $invoiceCount ?>
            </div>

            <!-- Fecha -->
            <div class="sgi-row-fact-date sgi-fg-muted">
                <?= $record->created?->format('d/m/Y') ?: '—' ?>
            </div>

            <!-- Pipeline mini + pill -->
            <div class="sgi-row-fact-pipeline">
                <?php if ($stageIdx >= 0): ?>
                <div class="sgi-pipeline-mini" aria-hidden="true">
                    <?php for ($i = 0, $n = count($pipelineSteps); $i < $n; $i++): ?>
                        <div class="<?= $i <= $stageIdx ? 'on' : '' ?>"></div>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <div class="sgi-row-fact-pills">
                    <span class="pill <?= h($pillClass) ?>">
                        <?php if ($isPaid): ?><i class="bi bi-check2" aria-hidden="true"></i><?php endif; ?>
                        <?= h(strtoupper($statusLabels[$record->status] ?? $record->status)) ?>
                    </span>
                </div>
            </div>

            <!-- Chevron -->
            <div class="sgi-row-fact-chevron">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </div>
        </a>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
        <div class="sgi-row-fact-empty">
            <i class="bi bi-inbox sgi-doc-empty-icon" aria-hidden="true"></i>
            No hay reintegros en tu bandeja actual.
        </div>
    <?php endif; ?>

    <?= $this->element('pagination') ?>
</div>

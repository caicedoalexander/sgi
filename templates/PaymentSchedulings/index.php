<?php
/**
 * Listado de Programaciones de Pago — Sistema de Diseño v2.
 *
 * Estructura espejo de templates/Invoices/index.php: header con meta, search +
 * filtros colapsables, chips por estado, tabla con grid CSS inline, .pipeline-mini
 * y pills soft, empty state y paginación.
 *
 * @var \App\View\AppView $this
 * @var iterable $records
 * @var string $roleName
 */

use App\Constants\PaymentSchedulingConstants;
use App\View\Presentation\PaymentSchedulingPresentation;

$pageTitle = 'Programación de Pagos';
$this->assign('title', $pageTitle);

$statusBadge   = PaymentSchedulingPresentation::STATUS_BADGES;
$statusLabels  = PaymentSchedulingConstants::STATUS_LABELS;
$pipelineSteps = PaymentSchedulingConstants::PIPELINE_STATUSES;

$query        = $this->request->getQueryParams();
$activeStatus = (string)($query['status'] ?? '');
$searchValue  = (string)($query['code'] ?? '');
$activeFilters = array_filter(
    $query,
    fn ($v, $k) => $k !== 'page' && $v !== '' && $v !== null,
    ARRAY_FILTER_USE_BOTH
);
$filterCount = count($activeFilters);

$recordsArr = is_array($records) ? $records : iterator_to_array($records, false);
$totalCount = $this->Paginator->counter('{{count}}');

$mesesEs = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$now         = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

$baseQuery = array_diff_key($query, ['status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($baseQuery): array {
    $params = ['action' => 'index'];
    if ($status !== null) {
        $params['?'] = $baseQuery + ['status' => $status];
    } elseif (!empty($baseQuery)) {
        $params['?'] = $baseQuery;
    }
    return $params;
};
/* [slug, label, color-css] */
$tabs = [
    [null,                                                 'Todas',        'var(--primary-color)'],
    [PaymentSchedulingConstants::STATUS_BORRADOR,          'Borrador',     'var(--text-muted)'],
    [PaymentSchedulingConstants::STATUS_TESORERIA,         'Tesorería',    'var(--accent-color)'],
    [PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO, 'Autorización', 'var(--warning-text)'],
    [PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO, 'Verificación', 'var(--warning-text)'],
    [PaymentSchedulingConstants::STATUS_PAGADA,            'Pagadas',      'var(--primary-color)'],
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
            <span style="color:var(--text-muted);"><?= $totalCount ?> programaciones</span>
        </div>
    </div>
    <div class="d-flex" style="gap:8px;">
        <?php if (!empty($userPermissions['payment_schedulings']['can_create'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg" aria-hidden="true"></i><span>Nueva Programación</span>',
                ['action' => 'add'],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>
</div>

<?php /* ════════════════════════ SEARCH + FILTROS ════════════════════════ */ ?>
<?= $this->Form->create(null, [
    'type'         => 'get',
    'url'          => ['action' => 'index'],
    'valueSources' => ['query'],
]) ?>
<div class="d-flex align-items-stretch" style="gap:8px;margin-bottom:14px;">
    <label class="input flex-grow-1" style="margin:0;">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="text" name="code"
               value="<?= h($searchValue) ?>"
               placeholder="Buscar por código (PRO-…)"
               aria-label="Buscar programaciones">
        <?php if ($searchValue !== ''): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x" aria-hidden="true"></i>',
                ['action' => 'index'],
                [
                    'escape' => false,
                    'style'  => 'background:transparent;border:0;color:var(--text-faint);padding:4px;display:inline-flex;',
                    'title'  => 'Limpiar búsqueda',
                ]
            ) ?>
        <?php endif; ?>
    </label>

    <button type="button" class="btn btn-default"
            data-bs-toggle="collapse" data-bs-target="#scheduleFilters"
            aria-expanded="<?= $filterCount > 0 ? 'true' : 'false' ?>"
            aria-label="Filtros avanzados">
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span>Filtros<?php if ($filterCount > 0): ?> · <span style="color:var(--primary-color);font-weight:700;"><?= $filterCount ?></span><?php endif; ?></span>
    </button>

    <?php if ($filterCount > 0): ?>
        <?= $this->Html->link(
            '<i class="bi bi-x-lg" aria-hidden="true"></i><span>Limpiar</span>',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost', 'escape' => false, 'style' => 'color:var(--danger-color);']
        ) ?>
    <?php endif; ?>
</div>

<div class="collapse <?= $filterCount > 0 ? 'show' : '' ?>" id="scheduleFilters" style="margin-bottom:14px;">
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
            <div class="col-md-3 d-flex align-items-end">
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

<?php /* ════════════════════════ TABLA DE PROGRAMACIONES ════════════════════════ */ ?>
<?php
/* Grid 7-col compartido entre header y filas. */
$gridStyle = 'display:grid;grid-template-columns:1.3fr 2.2fr 0.7fr 1.5fr 1fr 1.7fr 36px;gap:14px;align-items:center;';
?>
<div class="sgi-card" style="padding:0;">
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>Código</span>
        <span>Título</span>
        <span style="text-align:center;"># Items</span>
        <span>Creado por</span>
        <span>Fecha</span>
        <span>Estado · Pipeline</span>
        <span aria-hidden="true"></span>
    </div>

    <?php
    $rowCount = 0;
    foreach ($recordsArr as $i => $record):
        $rowCount++;
        $stageIdx = array_search($record->pipeline_status, $pipelineSteps, true);
        if ($stageIdx === false) {
            $stageIdx = -1;
        }
        $pillClass = $statusBadge[$record->pipeline_status] ?? 'pill-muted';
        $itemCount = count($record->payment_scheduling_items ?? []);
        $isPaid    = $record->pipeline_status === PaymentSchedulingConstants::STATUS_PAGADA;
        $href = $this->Url->build(['action' => 'edit', $record->id]);
    ?>
        <a href="<?= h($href) ?>" role="row"
           style="<?= $gridStyle ?>padding:14px 18px;background:#fff;color:inherit;text-decoration:none;cursor:pointer;transition:background-color var(--t-fast) ease;<?= $i > 0 ? 'border-top:1px solid var(--rule);' : '' ?>"
           onmouseenter="this.style.background='var(--bg-muted)'"
           onmouseleave="this.style.background='#fff'">

            <?php /* 1. Código */ ?>
            <div style="min-width:0;">
                <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);">
                    <?= h($record->code ?: '—') ?>
                </div>
                <div style="font-size:9.5px;color:var(--text-faint);letter-spacing:0.5px;font-weight:600;margin-top:2px;text-transform:uppercase;">
                    Programación
                </div>
            </div>

            <?php /* 2. Título */ ?>
            <div style="min-width:0;">
                <div style="font-size:12.5px;font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= !empty($record->title)
                        ? h($record->title)
                        : '<span style="color:var(--text-faint);">—</span>' ?>
                </div>
            </div>

            <?php /* 3. # Items */ ?>
            <div class="mono" style="text-align:center;font-size:12px;color:var(--text-muted);">
                <?= $itemCount ?>
            </div>

            <?php /* 4. Creado por */ ?>
            <div style="min-width:0;font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $record->hasValue('created_by_user')
                    ? h($record->created_by_user->full_name)
                    : '<span style="color:var(--text-faint);">—</span>' ?>
            </div>

            <?php /* 5. Fecha */ ?>
            <div class="mono" style="font-size:12px;color:var(--text-default);">
                <?= $record->created?->format('d/m/Y') ?: '—' ?>
            </div>

            <?php /* 6. Estado · Pipeline */ ?>
            <div style="min-width:0;">
                <?php if ($stageIdx >= 0): ?>
                    <div class="pipeline-mini" aria-hidden="true" style="margin-bottom:5px;max-width:100%;">
                        <?php for ($s = 0, $n = count($pipelineSteps); $s < $n; $s++): ?>
                            <div class="<?= $s <= $stageIdx ? 'on' : '' ?>"></div>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    <span class="pill <?= h($pillClass) ?> pill-sm">
                        <?php if ($isPaid): ?><i class="bi bi-check" style="font-size:9px;" aria-hidden="true"></i><?php endif; ?>
                        <?= h(strtoupper($statusLabels[$record->pipeline_status] ?? $record->pipeline_status)) ?>
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
            <div class="es-title">No hay programaciones en este filtro</div>
            <div class="es-msg">Cambia el filtro o crea una nueva programación.</div>
        </div>
    <?php endif; ?>

    <?php if ($rowCount > 0): ?>
        <?= $this->element('pagination') ?>
    <?php endif; ?>
</div>

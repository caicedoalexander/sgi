<?php
/**
 * @var \App\View\AppView $this
 * @var iterable $records
 * @var string $roleName
 */

use App\Constants\PaymentSchedulingConstants;
use App\View\Presentation\PaymentSchedulingPresentation;

$this->assign('title', 'Programación de Pagos');

$statusBadge  = PaymentSchedulingPresentation::STATUS_BADGES;
$statusLabels = PaymentSchedulingConstants::STATUS_LABELS;
$pipelineSteps = PaymentSchedulingConstants::PIPELINE_STATUSES;

$query = $this->request->getQueryParams();
$hasFilters = !empty(array_filter($query, fn($v) => $v !== '' && $v !== null));
$activeStatus = (string)($query['status'] ?? '');

// Materializar el ResultSet.
$recordsArr = is_array($records) ? $records : iterator_to_array($records, false);

$mesesEs = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$now = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

$baseQuery = array_diff_key($query, ['status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($baseQuery) {
    $params = ['action' => 'index'];
    if ($status !== null) { $params['?'] = $baseQuery + ['status' => $status]; }
    elseif (!empty($baseQuery)) { $params['?'] = $baseQuery; }
    return $params;
};
$tabs = [
    [null,                                                  'Todas'],
    [PaymentSchedulingConstants::STATUS_BORRADOR,           'Borrador'],
    [PaymentSchedulingConstants::STATUS_TESORERIA,          'Tesorería'],
    [PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO,  'Autorización'],
    [PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO,  'Verificación'],
    [PaymentSchedulingConstants::STATUS_PAGADA,             'Pagadas'],
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div>
        <span class="sgi-page-title">Programación de Pagos</span>
        <div class="sgi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            Período: <?= h($periodLabel) ?> ·
            <span class="sgi-fg-muted"><?= $this->Paginator->counter('{{count}} programaciones') ?></span>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['payment_schedulings']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Programación',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filters -->
<?= $this->Form->create(null, ['type' => 'get', 'url' => ['action' => 'index'], 'valueSources' => ['query']]) ?>
<div class="d-flex gap-2 align-items-stretch mb-3">
    <div class="sgi-search-bar flex-grow-1">
        <div class="sgi-input-icon w-100">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="text" name="code"
                   class="form-control"
                   placeholder="Buscar por código (PRO-...)"
                   value="<?= h($this->request->getQuery('code', '')) ?>"
                   aria-label="Buscar programaciones">
        </div>
    </div>
    <button type="button" class="btn btn-ghost-card sgi-filters-trigger"
            data-bs-toggle="collapse" data-bs-target="#scheduleFilters" aria-label="Filtros avanzados">
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span>Filtros<?php if ($hasFilters): ?> · <span class="sgi-fg-primary"><?= count(array_filter($query, fn($v) => $v !== '' && $v !== null)) ?></span><?php endif; ?></span>
    </button>
    <?php if ($hasFilters): ?>
        <?= $this->Html->link(
            '<i class="bi bi-x-lg" aria-hidden="true"></i> Limpiar',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card sgi-fg-danger', 'escape' => false]
        ) ?>
    <?php endif; ?>
</div>

<div class="collapse <?= $hasFilters ? 'show' : '' ?> mb-3" id="scheduleFilters">
    <div class="card p-3">
        <div class="row g-2">
            <div class="col-md-4">
                <label class="sgi-filter-label" for="filter-status">Estado</label>
                <?= $this->Form->select('status', $statusLabels, [
                    'empty' => 'Todos',
                    'class' => 'form-select form-select-sm',
                    'value' => $this->request->getQuery('status', ''),
                    'id'    => 'filter-status',
                ]) ?>
            </div>
            <div class="col-md-4 d-flex align-items-end">
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
            PaymentSchedulingConstants::STATUS_BORRADOR          => 'var(--text-muted)',
            PaymentSchedulingConstants::STATUS_TESORERIA         => 'var(--info-text)',
            PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO => 'var(--info-text)',
            PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO => 'var(--warning-text)',
            PaymentSchedulingConstants::STATUS_PAGADA            => 'var(--primary-color)',
            default                                              => 'var(--primary-color)',
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
// Columnas: Código | Título | # Items | Creado por | Fecha | Pipeline/Estado | chevron
$gridCols = '1.2fr 2.2fr 0.7fr 1.5fr 1fr 1.7fr 36px';
?>
<div class="card">
    <div class="sgi-row-fact-grid sgi-row-fact-head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Código</span>
        <span>Título</span>
        <span class="text-center"># Items</span>
        <span>Creado por</span>
        <span>Fecha</span>
        <span>Estado · Pipeline</span>
        <span aria-hidden="true"></span>
    </div>

    <?php
    $rowCount = 0;
    foreach ($recordsArr as $record):
        $rowCount++;
        $stageIdx = array_search($record->pipeline_status, $pipelineSteps, true);
        if ($stageIdx === false) { $stageIdx = -1; }
        $pillClass = $statusBadge[$record->pipeline_status] ?? 'pill-muted';
        $itemCount = count($record->payment_scheduling_items ?? []);
        $isPaid = $record->pipeline_status === PaymentSchedulingConstants::STATUS_PAGADA;
    ?>
        <a class="sgi-row-fact sgi-row-fact-grid"
           style="grid-template-columns:<?= $gridCols ?>;"
           href="<?= $this->Url->build(['action' => 'edit', $record->id]) ?>"
           role="row">

            <!-- Código -->
            <div>
                <div class="sgi-row-fact-id"><?= h($record->code ?: '—') ?></div>
                <div class="sgi-row-fact-type">Programación</div>
            </div>

            <!-- Título -->
            <div style="min-width:0;">
                <div class="sgi-row-fact-provider">
                    <?= !empty($record->title) ? h($record->title) : '<span class="sgi-fg-faint">—</span>' ?>
                </div>
            </div>

            <!-- # Items -->
            <div class="sgi-row-fact-date sgi-fg-muted text-center">
                <?= $itemCount ?>
            </div>

            <!-- Creado por -->
            <div style="min-width:0;">
                <div class="sgi-row-fact-provider">
                    <?= $record->hasValue('created_by_user') ? h($record->created_by_user->full_name) : '<span class="sgi-fg-faint">—</span>' ?>
                </div>
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
                        <?= h(strtoupper($statusLabels[$record->pipeline_status] ?? $record->pipeline_status)) ?>
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
            No hay programaciones en tu bandeja actual.
        </div>
    <?php endif; ?>

    <?= $this->element('pagination') ?>
</div>

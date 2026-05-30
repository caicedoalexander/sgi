<?php
/**
 * Listado de Documentos de Liquidación — Sistema de Diseño v2.
 *
 * Estructura espejo de templates/Invoices/index.php: header con meta, search bar,
 * chips por estado, tabla con grid CSS inline, pills soft, empty state y paginación.
 *
 * Vista única reutilizada por las acciones index / all / rejected.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\NoveltyLiquidationDoc> $liquidationDocs
 * @var string|null $statusFilter
 * @var array<string> $visibleStatuses
 */

use App\Constants\NoveltyConstants;
use App\View\Presentation\NoveltyPresentation;

$action = $this->request->getParam('action');
$titleMap = [
    'index'    => 'Mis Documentos de Liquidación',
    'all'      => 'Todos los Documentos de Liquidación',
    'rejected' => 'Documentos de Liquidación Rechazados',
];
$pageTitle = $titleMap[$action] ?? 'Documentos de Liquidación';
$this->assign('title', $pageTitle);

$query        = $this->request->getQueryParams();
$activeStatus = (string)($statusFilter ?? '');
$searchValue  = (string)($query['search'] ?? '');

// Materializar el ResultSet (PaginatedResultSet no garantiza count() ni rewind).
$docsArr    = is_array($liquidationDocs) ? $liquidationDocs : iterator_to_array($liquidationDocs, false);
$totalCount = $this->Paginator->counter('{{count}}');

$mesesEs = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$now         = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

// Chips por estado — ocultos en la bandeja rejected.
$showTabs  = $action !== 'rejected';
$baseQuery = array_diff_key($query, ['pipeline_status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($action, $baseQuery): array {
    $params = ['action' => $action ?: 'index'];
    if ($status !== null) {
        $params['?'] = $baseQuery + ['pipeline_status' => $status];
    } elseif (!empty($baseQuery)) {
        $params['?'] = $baseQuery;
    }
    return $params;
};
/* [slug, label, color-css] */
$tabs = [
    [null,                                       'Todos',         'var(--primary-color)'],
    [NoveltyConstants::STATUS_CONTABILIDAD,      'Contabilidad',  'var(--secondary-color)'],
    [NoveltyConstants::STATUS_REVISION_FIRMAS,   'Rev. Firmas',   'var(--warning-text)'],
    [NoveltyConstants::STATUS_GDP,               'GDP',           'var(--text-muted)'],
    [NoveltyConstants::STATUS_TESORERIA,         'Tesorería',     'var(--accent-color)'],
    [NoveltyConstants::STATUS_AUTORIZACION_PAGO, 'Autorización',  'var(--warning-text)'],
    [NoveltyConstants::STATUS_PAGADA,            'Pagados',       'var(--primary-color)'],
];

/* Grid 7-col compartido entre header y filas. */
$gridStyle = 'display:grid;grid-template-columns:1.3fr 1.1fr 1.4fr 0.8fr 1.6fr 1fr 36px;gap:14px;align-items:center;';
?>

<?php /* ════════════════════════ HEADER ════════════════════════ */ ?>
<div class="d-flex justify-content-between align-items-start" style="padding:4px 0 16px;">
    <div>
        <div style="font-size:22px;font-weight:700;color:var(--text-strong);letter-spacing:-0.2px;">
            <?= h($pageTitle) ?>
        </div>
        <div style="font-size:12px;color:var(--text-faint);margin-top:4px;">
            Período: <?= h($periodLabel) ?> ·
            <span style="color:var(--text-muted);"><?= $totalCount ?> documentos</span>
        </div>
    </div>
</div>

<?php /* ════════════════════════ SEARCH ════════════════════════ */ ?>
<?= $this->Form->create(null, [
    'type'         => 'get',
    'url'          => ['action' => $action ?: 'index'],
    'valueSources' => ['query'],
]) ?>
<div class="d-flex align-items-stretch" style="gap:8px;margin-bottom:14px;">
    <?php if ($activeStatus !== ''): ?>
        <input type="hidden" name="pipeline_status" value="<?= h($activeStatus) ?>">
    <?php endif; ?>
    <label class="input flex-grow-1" style="margin:0;">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="text" name="search"
               value="<?= h($searchValue) ?>"
               placeholder="Buscar por número de liquidación…"
               aria-label="Buscar documentos de liquidación">
        <?php if ($searchValue !== ''): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x" aria-hidden="true"></i>',
                $tabUrl($activeStatus !== '' ? $activeStatus : null),
                [
                    'escape' => false,
                    'style'  => 'background:transparent;border:0;color:var(--text-faint);padding:4px;display:inline-flex;',
                    'title'  => 'Limpiar búsqueda',
                ]
            ) ?>
        <?php endif; ?>
    </label>
</div>
<?= $this->Form->end() ?>

<?php /* ════════════════════════ CHIPS POR ESTADO ════════════════════════ */ ?>
<?php if ($showTabs): ?>
<div class="d-flex flex-wrap" style="gap:4px;margin-bottom:14px;" role="tablist" aria-label="Filtrar por estado">
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

<?php /* ════════════════════════ TABLA DE DOCUMENTOS ════════════════════════ */ ?>
<div class="sgi-card" style="padding:0;">
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>No. Liquidación</span>
        <span>Período</span>
        <span>Estado</span>
        <span style="text-align:center;">Novedades</span>
        <span>Elaborado por</span>
        <span>Fecha</span>
        <span aria-hidden="true"></span>
    </div>

    <?php
    $rowCount = 0;
    foreach ($docsArr as $i => $doc):
        $rowCount++;
        $row = NoveltyPresentation::forLiquidationDocRow($doc);
        $pillClass = $row->statusBadgeClass;
    ?>
        <a href="<?= $this->Url->build(['action' => 'edit', $doc->id]) ?>" role="row" class="row-fact"
           style="<?= $gridStyle ?>padding:14px 18px;">

            <?php /* 1. No. Liquidación */ ?>
            <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($doc->liquidation_number) ?>
            </div>

            <?php /* 2. Período */ ?>
            <div style="font-size:12px;color:var(--text-default);">
                <?= h($row->periodLabel) ?>
            </div>

            <?php /* 3. Estado */ ?>
            <div style="min-width:0;">
                <span class="pill <?= h($pillClass) ?> pill-sm">
                    <?= h(strtoupper($row->statusLabel)) ?>
                </span>
            </div>

            <?php /* 4. Novedades */ ?>
            <div class="mono" style="text-align:center;font-size:12px;color:var(--text-muted);">
                <?= $row->noveltyCount ?>
            </div>

            <?php /* 5. Elaborado por */ ?>
            <div style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($doc->performed_by_user->full_name ?? '—') ?>
            </div>

            <?php /* 6. Fecha */ ?>
            <div class="mono" style="font-size:12px;color:var(--text-faint);">
                <?= $doc->document_date?->format('d/m/Y') ?: '—' ?>
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
            <div class="es-title">No hay documentos de liquidación en este filtro</div>
            <div class="es-msg">Cambia el filtro o ajusta la búsqueda.</div>
        </div>
    <?php endif; ?>

    <?php if ($rowCount > 0): ?>
        <?= $this->element('pagination') ?>
    <?php endif; ?>
</div>

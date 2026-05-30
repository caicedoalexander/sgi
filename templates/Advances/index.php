<?php
/**
 * Listado de Anticipos — Sistema de Diseño v2.
 *
 * Estructura espejo de templates/Invoices/index.php adaptada a Anticipos:
 * header con meta, search bar, chips por estado, tabla con grid CSS inline,
 * .pipeline-mini y pills soft, empty state y paginación. Conserva las dos
 * columnas de pipeline propias de Anticipos (pago + legalización).
 *
 * Vista única reutilizada por las acciones index / all / pendingLegalization.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Invoice> $advances
 * @var string[] $visibleStatuses
 */

use App\Constants\InvoiceConstants;
use App\View\Presentation\AdvancePresentation;

$action = $this->request->getParam('action');
$pageTitles = [
    'all'                 => 'Todos los Anticipos',
    'pendingLegalization' => 'Pendientes de Legalización',
];
$pageTitle = $pageTitles[$action] ?? 'Anticipos';
$this->assign('title', $pageTitle);

$query        = $this->request->getQueryParams();
$activeStatus = (string)($query['pipeline_status'] ?? '');
$searchValue  = (string)($query['search'] ?? '');

// Materializar el ResultSet (PaginatedResultSet no garantiza count() ni rewind).
$advancesArr = is_array($advances) ? $advances : iterator_to_array($advances, false);
$pageTotal = 0.0;
foreach ($advancesArr as $a) {
    $pageTotal += (float)$a->amount;
}
$totalCount = $this->Paginator->counter('{{count}}');

$mesesEs = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$now         = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

// Chips por estado — ocultos en la bandeja pendingLegalization.
$showTabs  = $action !== 'pendingLegalization';
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
    [InvoiceConstants::STATUS_APROBACION,        'En aprobación', 'var(--warning-text)'],
    [InvoiceConstants::STATUS_CONTABILIDAD,      'Contabilidad',  'var(--secondary-color)'],
    [InvoiceConstants::STATUS_TESORERIA,         'Tesorería',     'var(--accent-color)'],
    [InvoiceConstants::STATUS_AUTORIZACION_PAGO, 'Autorización',  'var(--warning-text)'],
    [InvoiceConstants::STATUS_PAGADA,            'Pagados',       'var(--primary-color)'],
];

/* Grid 7-col compartido entre header y filas. */
$gridStyle = 'display:grid;grid-template-columns:1.2fr 2fr 1.2fr 1.1fr 1.7fr 1.7fr 36px;gap:14px;align-items:center;';
?>

<?php /* ════════════════════════ HEADER ════════════════════════ */ ?>
<div class="d-flex justify-content-between align-items-start" style="padding:4px 0 16px;">
    <div>
        <div style="font-size:22px;font-weight:700;color:var(--text-strong);letter-spacing:-0.2px;">
            <?= h($pageTitle) ?>
        </div>
        <div style="font-size:12px;color:var(--text-faint);margin-top:4px;">
            Período: <?= h($periodLabel) ?> ·
            <span style="color:var(--text-muted);"><?= $totalCount ?> anticipos</span> ·
            <span class="mono" style="color:var(--text-muted);">$ <?= number_format($pageTotal, 0, ',', '.') ?></span>
        </div>
    </div>
    <div class="d-flex" style="gap:8px;">
        <?php if (!empty($userPermissions['advances']['can_create'])): ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg" aria-hidden="true"></i><span>Nuevo Anticipo</span>',
                ['action' => 'add'],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
        <?php endif; ?>
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
               placeholder="Buscar por número de anticipo…"
               aria-label="Buscar anticipos">
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

<?php /* ════════════════════════ TABLA DE ANTICIPOS ════════════════════════ */ ?>
<div class="sgi-card" style="padding:0;">
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>Anticipo</span>
        <span>Beneficiario</span>
        <span>Centro Op.</span>
        <span style="text-align:right;">Monto</span>
        <span>Pago · Pipeline</span>
        <span>Legalización</span>
        <span aria-hidden="true"></span>
    </div>

    <?php
    $rowCount = 0;
    foreach ($advancesArr as $i => $a):
        $rowCount++;
        $row = AdvancePresentation::forRow($a);
    ?>
        <a href="<?= $this->Url->build(['action' => 'view', $a->id]) ?>" role="row" class="row-fact"
           style="<?= $gridStyle ?>padding:14px 18px;">

            <?php /* 1. Anticipo: código + tipo */ ?>
            <div style="min-width:0;">
                <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);">
                    <?= h($row->idLabel) ?>
                </div>
                <div style="font-size:9.5px;color:var(--text-faint);letter-spacing:0.5px;font-weight:600;margin-top:2px;text-transform:uppercase;">
                    Anticipo
                </div>
            </div>

            <?php /* 2. Beneficiario */ ?>
            <div style="min-width:0;">
                <div style="font-size:12.5px;font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= $row->beneficiaryName ? h($row->beneficiaryName) : '<span style="color:var(--text-faint);">—</span>' ?>
                </div>
            </div>

            <?php /* 3. Centro de operación */ ?>
            <div style="min-width:0;">
                <div style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= $row->operationCenterName !== null
                        ? h($row->operationCenterName)
                        : '<span style="color:var(--text-faint);">—</span>' ?>
                </div>
            </div>

            <?php /* 4. Monto */ ?>
            <div class="mono" style="text-align:right;font-size:13.5px;font-weight:700;<?= $row->isPaid
                ? 'color:var(--primary-color);'
                : 'color:var(--text-default);' ?>">
                $ <?= number_format($row->amount, 0, ',', '.') ?>
            </div>

            <?php /* 5. Pago · Pipeline */ ?>
            <div style="min-width:0;">
                <?php if ($row->pipelineIdx >= 0): ?>
                    <div class="pipeline-mini" aria-hidden="true" style="margin-bottom:5px;max-width:100%;">
                        <?php for ($s = 0; $s < $row->pipelineLength; $s++): ?>
                            <div class="<?= $s <= $row->pipelineIdx ? 'on' : '' ?>"></div>
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

            <?php /* 6. Legalización */ ?>
            <div style="min-width:0;">
                <?php if ($row->hasLegalization): ?>
                    <?php if ($row->legalizationIdx >= 0): ?>
                        <div class="pipeline-mini" aria-hidden="true" style="margin-bottom:5px;max-width:100%;">
                            <?php for ($s = 0; $s < $row->legalizationLength; $s++): ?>
                                <div class="<?= $s <= $row->legalizationIdx ? 'on' : '' ?>"></div>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                        <span class="pill <?= h($row->legalizationBadgeClass) ?> pill-sm">
                            <?= h(strtoupper($row->legalizationLabel)) ?>
                        </span>
                    </div>
                <?php else: ?>
                    <span style="font-size:11px;color:var(--text-faint);">Sin legalización</span>
                <?php endif; ?>
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
            <div class="es-title">No hay anticipos en este filtro</div>
            <div class="es-msg">Cambia el filtro o crea un nuevo anticipo.</div>
        </div>
    <?php endif; ?>

    <?php if ($rowCount > 0): ?>
        <?= $this->element('pagination') ?>
    <?php endif; ?>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Invoice> $advances
 * @var array $visibleStatuses
 */

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\View\Presentation\AdvancePresentation;
use App\View\Presentation\InvoicePresentation;

$action = $this->request->getParam('action');
$pageTitles = [
    'all'                => 'Todos los Anticipos',
    'pendingLegalization' => 'Pendientes de Legalización',
];
$pageTitle = $pageTitles[$action] ?? 'Anticipos';
$this->assign('title', $pageTitle);

$pipelineBadge      = InvoicePresentation::STATUS_BADGES;
$pipelineLabels     = InvoiceConstants::STATUS_LABELS;
$legalizationBadge  = AdvancePresentation::STATUS_BADGES;
$legalizationLabels = AdvanceConstants::STATUS_LABELS;
$invoicePipelineSteps = InvoiceConstants::PIPELINE_STATUSES;
$legalizationSteps    = AdvanceConstants::PIPELINE_STATUSES;

$query = $this->request->getQueryParams();
$hasFilters = !empty(array_filter($query, fn($v) => $v !== '' && $v !== null));
$activeStatus = (string)($query['pipeline_status'] ?? '');

// Materializar el ResultSet (PaginatedResultSet no garantiza count() ni rewind).
$advancesArr = is_array($advances) ? $advances : iterator_to_array($advances, false);
$pageTotal = 0.0;
foreach ($advancesArr as $a) { $pageTotal += (float)$a->amount; }

$mesesEs = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$now = new \DateTimeImmutable('today');
$periodLabel = $mesesEs[(int)$now->format('n')] . ' ' . $now->format('Y');

$baseQuery = array_diff_key($query, ['pipeline_status' => true, 'page' => true]);
$tabUrl = function (?string $status) use ($action, $baseQuery) {
    $params = ['action' => $action ?: 'index'];
    if ($status !== null) { $params['?'] = $baseQuery + ['pipeline_status' => $status]; }
    elseif (!empty($baseQuery)) { $params['?'] = $baseQuery; }
    return $params;
};
$tabs = [
    [null,                                          'Todos'],
    [InvoiceConstants::STATUS_APROBACION,           'En aprobación'],
    [InvoiceConstants::STATUS_CONTABILIDAD,         'Contabilidad'],
    [InvoiceConstants::STATUS_TESORERIA,            'Tesorería'],
    [InvoiceConstants::STATUS_AUTORIZACION_PAGO,    'Autorización'],
    [InvoiceConstants::STATUS_PAGADA,               'Pagados'],
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div>
        <span class="sgi-page-title"><?= $pageTitle ?></span>
        <div class="sgi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            Período: <?= h($periodLabel) ?> ·
            <span class="sgi-fg-muted"><?= $this->Paginator->counter('{{count}} anticipos') ?></span> ·
            <span class="sgi-fg-muted mono">$ <?= number_format($pageTotal, 0, ',', '.') ?></span>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['advances']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Anticipo',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($action !== 'pendingLegalization'): ?>
<!-- Tabs por estado de pipeline (sólo en vistas con flujo de pago) -->
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

<?php
// Columnas: Anticipo | Beneficiario | Centro op. | Monto | Pipeline pago | Legalización | chevron
$gridCols = '1.2fr 2fr 1.2fr 1.1fr 1.7fr 1.7fr 36px';
?>
<div class="card">
    <div class="sgi-row-fact-grid sgi-row-fact-head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Anticipo</span>
        <span>Beneficiario</span>
        <span>Centro Op.</span>
        <span class="text-end">Monto</span>
        <span>Pago · Pipeline</span>
        <span>Legalización</span>
        <span aria-hidden="true"></span>
    </div>

    <?php
    $rowCount = 0;
    foreach ($advancesArr as $a):
        $rowCount++;
        $pipelineIdx = array_search($a->pipeline_status, $invoicePipelineSteps, true);
        if ($pipelineIdx === false) { $pipelineIdx = -1; }
        $pillClass = $pipelineBadge[$a->pipeline_status] ?? 'pill-muted';
        $isPaid = $a->pipeline_status === InvoiceConstants::STATUS_PAGADA;
        $beneficiary = $a->provider->name ?? ($a->employee->full_name ?? null);

        $legalization = $a->advance_legalization ?? null;
        $legalizationIdx = $legalization
            ? array_search($legalization->status, $legalizationSteps, true)
            : false;
        if ($legalizationIdx === false) { $legalizationIdx = -1; }
    ?>
        <a class="sgi-row-fact sgi-row-fact-grid"
           style="grid-template-columns:<?= $gridCols ?>;"
           href="<?= $this->Url->build(['action' => 'view', $a->id]) ?>"
           role="row">

            <!-- Anticipo (código) -->
            <div>
                <div class="sgi-row-fact-id"><?= h($a->invoice_number ?: '#' . $a->id) ?></div>
                <div class="sgi-row-fact-type">Anticipo</div>
            </div>

            <!-- Beneficiario -->
            <div style="min-width:0;">
                <div class="sgi-row-fact-provider">
                    <?= $beneficiary ? h($beneficiary) : '<span class="sgi-fg-faint">—</span>' ?>
                </div>
            </div>

            <!-- Centro op. -->
            <div style="min-width:0;">
                <div class="sgi-row-fact-provider">
                    <?= $a->hasValue('operation_center') ? h($a->operation_center->name) : '<span class="sgi-fg-faint">—</span>' ?>
                </div>
            </div>

            <!-- Monto -->
            <div class="sgi-row-fact-amount<?= $isPaid ? ' is-paid' : '' ?>">
                $ <?= number_format((float)$a->amount, 0, ',', '.') ?>
            </div>

            <!-- Pipeline pago -->
            <div class="sgi-row-fact-pipeline">
                <?php if ($pipelineIdx >= 0): ?>
                <div class="sgi-pipeline-mini" aria-hidden="true">
                    <?php for ($i = 0, $n = count($invoicePipelineSteps); $i < $n; $i++): ?>
                        <div class="<?= $i <= $pipelineIdx ? 'on' : '' ?>"></div>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <div class="sgi-row-fact-pills">
                    <span class="pill <?= h($pillClass) ?>">
                        <?php if ($isPaid): ?><i class="bi bi-check2" aria-hidden="true"></i><?php endif; ?>
                        <?= h(strtoupper($pipelineLabels[$a->pipeline_status] ?? $a->pipeline_status)) ?>
                    </span>
                </div>
            </div>

            <!-- Legalización -->
            <div class="sgi-row-fact-pipeline">
                <?php if ($legalization): ?>
                    <?php if ($legalizationIdx >= 0): ?>
                    <div class="sgi-pipeline-mini" aria-hidden="true">
                        <?php for ($i = 0, $n = count($legalizationSteps); $i < $n; $i++): ?>
                            <div class="<?= $i <= $legalizationIdx ? 'on' : '' ?>"></div>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    <div class="sgi-row-fact-pills">
                        <span class="pill <?= $legalizationBadge[$legalization->status] ?? 'pill-muted' ?>">
                            <?= h(strtoupper($legalizationLabels[$legalization->status] ?? $legalization->status)) ?>
                        </span>
                    </div>
                <?php else: ?>
                    <span class="sgi-fg-faint" style="font-size:var(--fs-body-sm);">Sin legalización</span>
                <?php endif; ?>
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
            No hay anticipos en tu bandeja actual.
        </div>
    <?php endif; ?>

    <?= $this->element('pagination') ?>
</div>

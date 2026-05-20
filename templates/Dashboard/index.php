<?php
/**
 * @var \App\View\AppView $this
 * @var array $invoiceStats
 * @var array $recentInvoices
 * @var array $rrhhStats
 * @var array $recentNovelties
 * @var array $catalogStats
 * @var array $adminStats
 * @var array $userPermissions
 * @var object|null $currentUser
 * @var array $invoiceFinancialStats
 * @var array $invoiceChartData
 * @var array $rrhhExtendedStats
 * @var array $rrhhChartData
 * @var string $currentPeriod
 * @var string $dateFrom
 * @var string $dateTo
 */
use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

$this->assign('title', 'Inicio');

$userPermissions = $userPermissions ?? [];
$canView   = fn(string $module): bool => !empty($userPermissions[$module]['can_view']);
$canCreate = fn(string $module): bool => !empty($userPermissions[$module]['can_create']);

$invoiceStats          = $invoiceStats ?? [];
$recentInvoices        = $recentInvoices ?? [];
$invoiceFinancialStats = $invoiceFinancialStats ?? [];
$invoiceChartData      = $invoiceChartData ?? [];
$rrhhStats             = $rrhhStats ?? [];
$recentNovelties       = $recentNovelties ?? [];
$rrhhExtendedStats     = $rrhhExtendedStats ?? [];
$rrhhChartData         = $rrhhChartData ?? [];
$catalogStats          = $catalogStats ?? [];
$adminStats            = $adminStats ?? [];
$currentPeriod         = $currentPeriod ?? 'month';
$dateFrom              = $dateFrom ?? '';
$dateTo                = $dateTo ?? '';

// ── Helper de moneda: formato Colombia "$ 120.000" ──────────────────
$fmtMoney = fn(float $n): string => '$ ' . number_format($n, 0, ',', '.');
$fmtNum   = fn(float $n): string => number_format($n, 0, ',', '.');

// ── Fecha y saludo ──────────────────────────────────────────────────
$dias  = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
          'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$mesesAbbr = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$hoy   = new DateTime();
$fecha = $dias[(int)$hoy->format('w')] . ', ' . $hoy->format('j') . ' de '
       . $meses[(int)$hoy->format('n') - 1] . ' de ' . $hoy->format('Y');
$hora  = (int)$hoy->format('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$firstName = $currentUser ? trim((string)explode(' ', trim((string)$currentUser->full_name))[0]) : '';

// ── Facturas que requieren acción (aprobación + vencidas) ───────────
$actionCount = (int)($invoiceStats['aprobacion'] ?? 0) + (int)($invoiceFinancialStats['overdue'] ?? 0);

// ── Renderiza una etiqueta de sección (icono + label + línea) ───────
$sectionHead = function (string $icon, string $label) {
    echo '<div class="d-flex align-items-center gap-2 mb-3">'
       . '<i class="bi ' . $icon . '" style="color:var(--primary-color);font-size:1rem;" aria-hidden="true"></i>'
       . '<span class="sgi-label">' . h($label) . '</span>'
       . '<div style="flex:1;height:1px;background:var(--rule);"></div>'
       . '</div>';
};
?>

<?php /* ════════════════════════ ENCABEZADO ════════════════════════ */ ?>
<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-2">
            <span style="width:3px;height:12px;background:var(--primary-color);"></span>
            <span class="sgi-label">Compañía Operadora Portuaria Cafetera S.A.</span>
        </div>
        <div class="sgi-title-page" style="font-size:1.5rem;">
            <?php if ($firstName !== ''): ?>
                <?= h($saludo) ?>, <span style="color:var(--primary-color);"><?= h($firstName) ?></span>
            <?php else: ?>
                <?= h($saludo) ?>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center flex-wrap gap-2 mt-2" style="font-size:var(--fs-body);color:var(--text-muted);">
            <span class="mono"><?= h($fecha) ?></span>
            <?php if ($actionCount > 0): ?>
                <span style="color:var(--text-disabled);">·</span>
                <span class="d-inline-flex align-items-center gap-1">
                    <span style="width:6px;height:6px;border-radius:50%;background:var(--primary-color);"></span>
                    Hay <strong style="color:var(--text-default);"><?= $actionCount ?></strong>
                    <?= $actionCount === 1 ? 'factura que requiere acción' : 'facturas que requieren acción' ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canCreate('invoices')): ?>
    <div>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg" style="font-size:12px;"></i> Nueva Factura',
            ['controller' => 'Invoices', 'action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false],
        ) ?>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('period_selector', compact('currentPeriod', 'dateFrom', 'dateTo')) ?>

<div class="d-flex flex-column gap-4">

<?php /* ════════════════ 1 · ATENCIÓN — REQUIERE ACCIÓN ═══════════════ */ ?>
<?php if (!empty($invoiceStats)): ?>
<?php
$attentionCards = [
    [
        'tone'    => 'danger',
        'accent'  => 'accent-danger',
        'color'   => 'var(--danger-color)',
        'count'   => (int)($invoiceFinancialStats['overdue'] ?? 0),
        'label'   => 'Facturas vencidas',
        'context' => 'Superaron su fecha de vencimiento sin estar pagadas.',
        'cta'     => 'Ir a vencidas',
        'url'     => $this->Url->build(['controller' => 'Invoices', 'action' => 'all']),
    ],
    [
        'tone'    => 'warning',
        'accent'  => 'accent-warning',
        'color'   => 'var(--warning-text)',
        'count'   => (int)($invoiceStats['aprobacion'] ?? 0),
        'label'   => 'En aprobación',
        'context' => 'Esperando revisión para avanzar en el pipeline.',
        'cta'     => 'Revisar pendientes',
        'url'     => $this->Url->build(['controller' => 'Invoices', 'action' => 'index']),
    ],
    [
        'tone'    => 'secondary',
        'accent'  => 'accent-orange',
        'color'   => 'var(--secondary-color)',
        'count'   => (int)($invoiceStats['rechazada'] ?? 0),
        'label'   => 'Facturas rechazadas',
        'context' => 'Requieren corrección y reinicio del flujo de aprobación.',
        'cta'     => 'Ver rechazadas',
        'url'     => $this->Url->build(['controller' => 'Invoices', 'action' => 'all']),
    ],
];
?>
<div>
    <?php $sectionHead('bi-exclamation-triangle', 'Atención · requiere acción'); ?>
    <div class="row g-3">
        <?php foreach ($attentionCards as $c): ?>
        <div class="col-12 col-md-4">
            <div class="sgi-card h-100 d-flex flex-column" style="position:relative;gap:10px;min-height:158px;">
                <span class="accent-strip <?= $c['accent'] ?>"></span>
                <div>
                    <div class="sgi-display" style="color:<?= $c['color'] ?>;"><?= $fmtNum((float)$c['count']) ?></div>
                    <div class="sgi-title-card" style="margin-top:6px;"><?= h($c['label']) ?></div>
                </div>
                <div class="sgi-body-faint grow" style="line-height:1.5;"><?= h($c['context']) ?></div>
                <a href="<?= $c['url'] ?>" class="d-inline-flex align-items-center gap-1"
                   style="text-decoration:none;font-size:11.5px;font-weight:700;color:<?= $c['color'] ?>;">
                    <?= h($c['cta']) ?> <i class="bi bi-chevron-right" style="font-size:10px;"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php /* ═══════════════════ 2 · FACTURACIÓN ════════════════════════ */ ?>
<?php if (!empty($invoiceStats)): ?>
<div>
    <?php $sectionHead('bi-receipt', 'Facturación'); ?>

    <?php /* ── KPIs financieros ───────────────────────────────── */ ?>
    <?php if (!empty($invoiceFinancialStats)): ?>
    <?php
    $totalPaid      = (float)($invoiceFinancialStats['total_paid'] ?? 0);
    $totalInProcess = (float)($invoiceFinancialStats['total_in_process'] ?? 0);
    $avgAmount      = (float)($invoiceFinancialStats['avg_amount'] ?? 0);
    $activeCount    = (int)($invoiceStats['aprobacion'] ?? 0)
                    + (int)($invoiceStats['contabilidad'] ?? 0)
                    + (int)($invoiceStats['tesoreria'] ?? 0);
    $financialKpis = [
        ['label' => 'Total facturado', 'value' => $fmtMoney($totalPaid + $totalInProcess),
         'sub' => $fmtNum((float)($invoiceStats['total'] ?? 0)) . ' documentos', 'color' => 'var(--text-strong)'],
        ['label' => 'Pagado', 'value' => $fmtMoney($totalPaid),
         'sub' => $fmtNum((float)($invoiceStats['pagada'] ?? 0)) . ' facturas completadas', 'color' => 'var(--primary-color)'],
        ['label' => 'En proceso', 'value' => $fmtMoney($totalInProcess),
         'sub' => $fmtNum((float)$activeCount) . ' facturas activas', 'color' => 'var(--secondary-color)'],
        ['label' => 'Promedio', 'value' => $fmtMoney($avgAmount),
         'sub' => 'por factura', 'color' => 'var(--text-default)'],
    ];
    ?>
    <div class="sgi-card mb-3">
        <div class="row g-0">
            <?php foreach ($financialKpis as $i => $k): ?>
            <div class="col-6 col-lg-3">
                <div style="<?= $i % 4 === 0 ? '' : 'padding-left:18px;' ?>padding-right:18px;<?= $i < 3 ? 'border-right:1px solid var(--rule);' : '' ?>min-width:0;">
                    <div class="sgi-label" style="margin-bottom:6px;"><?= h($k['label']) ?></div>
                    <div class="mono" style="font-size:21px;font-weight:800;letter-spacing:-0.5px;line-height:1.1;color:<?= $k['color'] ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= h($k['value']) ?>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= h($k['sub']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php /* ── Gráficas: distribución por estado + por mes ──────── */ ?>
    <?php if (!empty($invoiceChartData)): ?>
    <?php
    $donut = $invoiceChartData['donut_status'] ?? [];
    $pipelineSegments = [
        ['key' => InvoiceConstants::STATUS_APROBACION,   'label' => 'Aprobación',   'color' => 'var(--warning-color)'],
        ['key' => InvoiceConstants::STATUS_CONTABILIDAD, 'label' => 'Contabilidad', 'color' => 'var(--secondary-color)'],
        ['key' => InvoiceConstants::STATUS_TESORERIA,    'label' => 'Tesorería',    'color' => 'var(--accent-color)'],
        ['key' => InvoiceConstants::STATUS_PAGADA,       'label' => 'Pagada',       'color' => 'var(--primary-color)'],
    ];
    $segMap = [
        InvoiceConstants::STATUS_APROBACION   => 'aprobacion',
        InvoiceConstants::STATUS_CONTABILIDAD => 'contabilidad',
        InvoiceConstants::STATUS_TESORERIA    => 'tesoreria',
        InvoiceConstants::STATUS_PAGADA       => 'pagada',
    ];
    $segTotalCount = 0;
    $segTotalMonto = 0.0;
    foreach ($pipelineSegments as $idx => $seg) {
        $count = (int)($invoiceStats[$segMap[$seg['key']]] ?? 0);
        $pipelineSegments[$idx]['count'] = $count;
        $pipelineSegments[$idx]['monto'] = (float)($donut[$seg['key']] ?? 0);
        $segTotalCount += $count;
        $segTotalMonto += $pipelineSegments[$idx]['monto'];
    }

    $monthly = $invoiceChartData['monthly'] ?? [];
    $maxMonthly = 0.0;
    foreach ($monthly as $row) {
        $maxMonthly = max($maxMonthly, (float)($row['total'] ?? 0));
    }
    ?>
    <div class="row g-3">
        <?php /* Distribución por estado — barra apilada CSS */ ?>
        <div class="col-12 col-lg-5">
            <div class="sgi-card h-100">
                <div class="sgi-label mb-2">Distribución por estado</div>
                <div class="d-flex align-items-baseline gap-2 mb-3">
                    <span class="mono" style="font-size:24px;font-weight:800;letter-spacing:-0.5px;color:var(--text-strong);">
                        <?= $fmtMoney($segTotalMonto) ?>
                    </span>
                    <span style="font-size:11.5px;color:var(--text-muted);">· <?= $fmtNum((float)$segTotalCount) ?> facturas</span>
                </div>
                <?php if ($segTotalCount > 0): ?>
                    <div class="d-flex mb-3" style="height:12px;gap:2px;">
                        <?php foreach ($pipelineSegments as $seg): ?>
                            <?php if ($seg['count'] > 0): ?>
                            <div title="<?= h($seg['label']) ?>: <?= $seg['count'] ?>"
                                 style="flex:<?= $seg['count'] ?>;background:<?= $seg['color'] ?>;"></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($pipelineSegments as $seg): ?>
                        <?php $pct = $segTotalCount > 0 ? round($seg['count'] / $segTotalCount * 100) : 0; ?>
                        <div class="d-flex align-items-center gap-2" style="font-size:12px;">
                            <span style="width:8px;height:8px;border-radius:2px;background:<?= $seg['color'] ?>;flex-shrink:0;"></span>
                            <span class="grow" style="color:var(--text-default);font-weight:600;"><?= h($seg['label']) ?></span>
                            <span class="mono" style="color:var(--text-faint);white-space:nowrap;">
                                <?= $seg['count'] ?> · <?= $pct ?>%
                            </span>
                            <span class="mono" style="color:var(--text-default);font-weight:600;text-align:right;min-width:96px;">
                                <?= $fmtMoney($seg['monto']) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="sgi-body-faint">Sin facturas registradas en el período.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php /* Facturación por mes — barras CSS */ ?>
        <div class="col-12 col-lg-7">
            <div class="sgi-card h-100">
                <div class="sgi-label mb-2">Facturación por mes</div>
                <?php if (!empty($monthly) && $maxMonthly > 0): ?>
                    <div class="d-flex align-items-baseline gap-2 mb-3">
                        <span class="mono" style="font-size:24px;font-weight:800;letter-spacing:-0.5px;color:var(--text-strong);">
                            <?= $fmtMoney(array_sum(array_map(fn($r) => (float)($r['total'] ?? 0), $monthly))) ?>
                        </span>
                        <span style="font-size:11.5px;color:var(--text-muted);">
                            · <?= count($monthly) ?> <?= count($monthly) === 1 ? 'mes' : 'meses' ?>
                        </span>
                    </div>
                    <div class="d-flex align-items-end gap-2" style="height:150px;padding-top:4px;">
                        <?php foreach ($monthly as $row): ?>
                        <?php
                        $total = (float)($row['total'] ?? 0);
                        $h = $maxMonthly > 0 ? max(2, (int)round($total / $maxMonthly * 130)) : 2;
                        $mStr = (string)($row['month'] ?? '');
                        $mNum = (int)substr($mStr, 5, 2);
                        $mLabel = $mesesAbbr[$mNum] ?? $mStr;
                        ?>
                        <div class="d-flex flex-column align-items-center grow" style="gap:6px;min-width:0;"
                             title="<?= h($mLabel) ?>: <?= $fmtMoney($total) ?> · <?= (int)($row['count'] ?? 0) ?> facturas">
                            <div style="width:100%;display:flex;align-items:flex-end;height:130px;">
                                <div style="width:100%;height:<?= $h ?>px;background:var(--primary-soft);border-top:2px solid var(--primary-color);"></div>
                            </div>
                            <div class="mono" style="font-size:9.5px;color:var(--text-disabled);"><?= h($mLabel) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="sgi-body-faint">Sin facturación registrada en el período.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php /* ── Actividad reciente (feed de facturas) ──────────────── */ ?>
    <?php if (!empty($recentInvoices)): ?>
    <div class="card mt-3">
        <div class="d-flex align-items-center justify-content-between" style="padding:14px 18px;border-bottom:1px solid var(--rule);">
            <div>
                <div class="sgi-title-card">Actividad reciente</div>
                <div class="sgi-body-faint" style="margin-top:2px;">Últimas facturas modificadas</div>
            </div>
            <?= $this->Html->link(
                'Ver todas <i class="bi bi-chevron-right" style="font-size:10px;"></i>',
                ['controller' => 'Invoices', 'action' => 'all'],
                ['escape' => false, 'style' => 'font-size:11.5px;font-weight:700;color:var(--secondary-color);text-decoration:none;'],
            ) ?>
        </div>
        <?php foreach ($recentInvoices as $i => $invoice): ?>
        <?php
        $isRejected = ($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED;
        $pillClass  = $isRejected
            ? 'pill-danger-soft'
            : (InvoicePresentation::STATUS_BADGES[$invoice->pipeline_status] ?? 'pill-muted');
        $pillLabel  = $isRejected
            ? 'Rechazada'
            : (InvoiceConstants::STATUS_LABELS[$invoice->pipeline_status] ?? $invoice->pipeline_status);
        ?>
        <a href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $invoice->id]) ?>"
           class="row-flex gap-12"
           style="padding:12px 18px;text-decoration:none;<?= $i > 0 ? 'border-top:1px solid var(--rule);' : '' ?>">
            <div class="d-flex align-items-center justify-content-center"
                 style="width:28px;height:28px;background:var(--bg-subtle);color:var(--primary-color);flex-shrink:0;border-radius:3px;">
                <i class="bi bi-receipt" style="font-size:13px;"></i>
            </div>
            <div class="grow">
                <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($invoice->invoice_number ?: '#' . $invoice->id) ?>
                </div>
                <div class="sgi-body-faint" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($invoice->provider->name ?? '—') ?>
                </div>
            </div>
            <span class="pill <?= $pillClass ?>"><?= h($pillLabel) ?></span>
            <div class="mono" style="font-size:10.5px;color:var(--text-faint);text-align:right;flex-shrink:0;">
                <?= $invoice->modified ? $invoice->modified->format('d/m/Y') : '—' ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ═══════════════════════ 3 · RRHH ═══════════════════════════ */ ?>
<?php if (!empty($rrhhStats) || !empty($recentNovelties)): ?>
<div>
    <?php $sectionHead('bi-people-fill', 'Recursos Humanos'); ?>

    <?php /* ── KPIs de RRHH ───────────────────────────────────── */ ?>
    <?php
    $rrhhKpis = [];
    if (isset($rrhhStats['active_employees'])) {
        $rrhhKpis[] = ['label' => 'Empleados activos', 'value' => $fmtNum((float)$rrhhStats['active_employees']),
            'sub' => 'En nómina', 'color' => 'var(--text-strong)'];
    }
    if (isset($rrhhStats['active_novelties'])) {
        $rrhhKpis[] = ['label' => 'Novedades vigentes', 'value' => $fmtNum((float)$rrhhStats['active_novelties']),
            'sub' => 'Hoy', 'color' => 'var(--primary-color)'];
    }
    if (isset($rrhhExtendedStats['new_hires'])) {
        $rrhhKpis[] = ['label' => 'Nuevos ingresos', 'value' => $fmtNum((float)$rrhhExtendedStats['new_hires']),
            'sub' => 'En el período', 'color' => 'var(--primary-color)'];
    }
    if (isset($rrhhExtendedStats['terminations'])) {
        $rrhhKpis[] = ['label' => 'Retiros', 'value' => $fmtNum((float)$rrhhExtendedStats['terminations']),
            'sub' => 'En el período', 'color' => 'var(--danger-color)'];
    }
    if (isset($rrhhExtendedStats['avg_tenure'])) {
        $rrhhKpis[] = ['label' => 'Antigüedad media', 'value' => $rrhhExtendedStats['avg_tenure'] . ' años',
            'sub' => 'Por empleado activo', 'color' => 'var(--text-default)'];
    }
    $rrhhCount = count($rrhhKpis);
    ?>
    <?php if ($rrhhCount > 0): ?>
    <div class="sgi-card mb-3">
        <div class="row g-3">
            <?php foreach ($rrhhKpis as $i => $k): ?>
            <div class="col-6 col-md-4 col-xl">
                <div style="padding-right:18px;<?= $i < $rrhhCount - 1 ? 'border-right:1px solid var(--rule);' : '' ?>min-width:0;">
                    <div class="sgi-label" style="margin-bottom:6px;"><?= h($k['label']) ?></div>
                    <div class="mono" style="font-size:21px;font-weight:800;letter-spacing:-0.5px;line-height:1.1;color:<?= $k['color'] ?>;">
                        <?= h($k['value']) ?>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= h($k['sub']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3">
        <?php /* ── Distribución por contrato — barra apilada CSS ── */ ?>
        <?php if (!empty($rrhhChartData['donut_contract'])): ?>
        <?php
        $contractColors = ['var(--primary-color)', 'var(--secondary-color)', 'var(--accent-color)',
                           'var(--bg-dark)', 'var(--info-color)'];
        $contractData = [];
        $contractTotal = 0;
        $ci = 0;
        foreach ($rrhhChartData['donut_contract'] as $type => $count) {
            $count = (int)$count;
            $contractData[] = ['label' => $type, 'count' => $count,
                'color' => $contractColors[$ci % count($contractColors)]];
            $contractTotal += $count;
            $ci++;
        }
        ?>
        <div class="col-12 col-lg-5">
            <div class="sgi-card h-100">
                <div class="sgi-label mb-2">Distribución por contrato</div>
                <div class="d-flex align-items-baseline gap-2 mb-3">
                    <span class="mono" style="font-size:24px;font-weight:800;letter-spacing:-0.5px;color:var(--text-strong);">
                        <?= $fmtNum((float)$contractTotal) ?>
                    </span>
                    <span style="font-size:11.5px;color:var(--text-muted);">· empleados activos</span>
                </div>
                <?php if ($contractTotal > 0): ?>
                    <div class="d-flex mb-3" style="height:12px;gap:2px;">
                        <?php foreach ($contractData as $seg): ?>
                            <?php if ($seg['count'] > 0): ?>
                            <div title="<?= h($seg['label']) ?>: <?= $seg['count'] ?>"
                                 style="flex:<?= $seg['count'] ?>;background:<?= $seg['color'] ?>;"></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($contractData as $seg): ?>
                        <?php if ($seg['count'] <= 0) continue; ?>
                        <?php $pct = round($seg['count'] / $contractTotal * 100); ?>
                        <div class="d-flex align-items-center gap-2" style="font-size:12px;">
                            <span style="width:8px;height:8px;border-radius:2px;background:<?= $seg['color'] ?>;flex-shrink:0;"></span>
                            <span class="grow" style="color:var(--text-default);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= h($seg['label']) ?>
                            </span>
                            <span class="mono" style="color:var(--text-faint);white-space:nowrap;">
                                <?= $seg['count'] ?> · <?= $pct ?>%
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="sgi-body-faint">Sin empleados activos registrados.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php /* ── Novedades recientes (feed) ─────────────────────── */ ?>
        <?php if (!empty($recentNovelties)): ?>
        <div class="<?= !empty($rrhhChartData['donut_contract']) ? 'col-12 col-lg-7' : 'col-12' ?>">
            <div class="card h-100">
                <div class="d-flex align-items-center justify-content-between" style="padding:14px 18px;border-bottom:1px solid var(--rule);">
                    <div>
                        <div class="sgi-title-card">Novedades recientes</div>
                        <div class="sgi-body-faint" style="margin-top:2px;">Últimas novedades registradas</div>
                    </div>
                    <?= $this->Html->link(
                        'Ver todas <i class="bi bi-chevron-right" style="font-size:10px;"></i>',
                        ['controller' => 'EmployeeNovelties', 'action' => 'index'],
                        ['escape' => false, 'style' => 'font-size:11.5px;font-weight:700;color:var(--secondary-color);text-decoration:none;'],
                    ) ?>
                </div>
                <?php foreach ($recentNovelties as $i => $novelty): ?>
                <?php
                $empName = trim((string)($novelty->employee->full_name ?? ''));
                $parts = array_filter(explode(' ', $empName));
                $initials = '';
                foreach (array_slice($parts, 0, 2) as $p) {
                    $initials .= mb_substr($p, 0, 1);
                }
                $initials = mb_strtoupper($initials !== '' ? $initials : '—');
                ?>
                <a href="<?= $this->Url->build(['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id]) ?>"
                   class="row-flex gap-12"
                   style="padding:12px 18px;text-decoration:none;<?= $i > 0 ? 'border-top:1px solid var(--rule);' : '' ?>">
                    <div class="av av-sm"><?= h($initials) ?></div>
                    <div class="grow">
                        <div style="font-size:12.5px;font-weight:700;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= h($empName !== '' ? $empName : '—') ?>
                        </div>
                        <div class="sgi-body-faint" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= h($novelty->novelty_type->name ?? '—') ?>
                        </div>
                    </div>
                    <div class="mono" style="font-size:10.5px;color:var(--text-faint);text-align:right;flex-shrink:0;">
                        <?= $novelty->created ? $novelty->created->format('d/m/Y') : '—' ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php /* ═══════════ 4 · CATÁLOGOS Y ADMINISTRACIÓN (strip) ══════════ */ ?>
<?php
$catalogLinks = [];
if (isset($catalogStats['providers'])) {
    $catalogLinks[] = ['label' => 'Proveedores', 'value' => $catalogStats['providers'],
        'url' => ['controller' => 'Providers', 'action' => 'index']];
}
if (isset($catalogStats['operation_centers'])) {
    $catalogLinks[] = ['label' => 'Centros de operación', 'value' => $catalogStats['operation_centers'],
        'url' => ['controller' => 'OperationCenters', 'action' => 'index']];
}
if (isset($catalogStats['expense_types'])) {
    $catalogLinks[] = ['label' => 'Tipos de gasto', 'value' => $catalogStats['expense_types'],
        'url' => ['controller' => 'ExpenseTypes', 'action' => 'index']];
}
if (isset($catalogStats['cost_centers'])) {
    $catalogLinks[] = ['label' => 'Centros de costos', 'value' => $catalogStats['cost_centers'],
        'url' => ['controller' => 'CostCenters', 'action' => 'index']];
}
if (isset($adminStats['users'])) {
    $catalogLinks[] = ['label' => 'Usuarios', 'value' => $adminStats['users'],
        'url' => ['controller' => 'Users', 'action' => 'index']];
}
if (isset($adminStats['roles'])) {
    $catalogLinks[] = ['label' => 'Roles', 'value' => $adminStats['roles'],
        'url' => ['controller' => 'Roles', 'action' => 'index']];
}
?>
<?php if (!empty($catalogLinks)): ?>
<div class="card d-flex flex-row flex-wrap align-items-center" style="padding:14px 20px;gap:24px;">
    <span class="sgi-label">Catálogos</span>
    <?php foreach ($catalogLinks as $link): ?>
        <?= $this->Html->link(
            '<span>' . h($link['label']) . '</span> '
            . '<span class="mono" style="color:var(--text-strong);font-weight:700;">'
            . $fmtNum((float)$link['value']) . '</span>',
            $link['url'],
            [
                'escape' => false,
                'class' => 'd-inline-flex align-items-center gap-1',
                'style' => 'text-decoration:none;font-size:11.5px;color:var(--text-muted);',
            ],
        ) ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php /* ── Estado vacío: sin permisos de visualización ────────────── */ ?>
<?php if (empty($invoiceStats) && empty($rrhhStats) && empty($recentNovelties) && empty($catalogLinks)): ?>
<div class="sgi-card text-center" style="padding:48px 20px;">
    <i class="bi bi-grid" style="font-size:1.6rem;color:var(--text-disabled);" aria-hidden="true"></i>
    <div class="sgi-title-card" style="margin-top:10px;">No hay módulos disponibles</div>
    <div class="sgi-body-faint" style="margin-top:4px;">El usuario no tiene permisos de visualización asignados.</div>
</div>
<?php endif; ?>

</div>

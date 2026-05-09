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
$canView = fn(string $module): bool => !empty($userPermissions[$module]['can_view']);

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

$dias  = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
          'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$hoy   = new DateTime();
$fecha = $dias[$hoy->format('w')] . ', ' . $hoy->format('j') . ' de ' . $meses[(int)$hoy->format('n') - 1] . ' de ' . $hoy->format('Y');

?>

<!-- Encabezado de bienvenida -->
<div class="mb-5">
    <p class="mb-1 text-uppercase fw-semibold"
       style="font-size:.65rem;letter-spacing:.14em;color:var(--primary-color);">
        Compañía Operadora Portuaria Cafetera S.A.
    </p>
    <span class="sgi-page-title" style="font-size:1.8rem">
        <?= $currentUser ? 'Bienvenido, ' . h($currentUser->full_name) : 'Bienvenido' ?>
    </span>
    <p class="mb-0 text-muted" style="font-size:.82rem;"><?= $fecha ?></p>
</div>

<?= $this->element('period_selector', compact('currentPeriod', 'dateFrom', 'dateTo')) ?>

<div class="d-flex flex-column gap-5">

<?php /* ========================================================
   SECCIÓN: FACTURACIÓN
   ======================================================== */ ?>
<?php if (!empty($invoiceStats)): ?>
<div>
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-receipt" style="color:var(--primary-color);font-size:1rem;"></i>
        <span style="font-size:.65rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6c757d;">Facturación</span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>

    <?php
    // Colores de stat cards alineados con InvoicePresentation::STATUS_BADGES
    // (bg-warning #ffc107, bg-primary #0d6efd, bg-info #0dcaf0, bg-success #198754, bg-danger #dc3545).
    $invoiceStatCards = [
        ['key' => 'total',             'label' => 'Total',         'sublabel' => 'Facturas',           'color' => null,       'valueColor' => '#212529'],
        ['key' => 'aprobacion',        'label' => 'Aprobación',    'sublabel' => 'Pendientes',         'color' => '#ffc107', 'valueColor' => '#212529'],
        ['key' => 'contabilidad',      'label' => 'Contabilidad',  'sublabel' => 'En proceso',         'color' => '#0d6efd', 'valueColor' => '#212529'],
        ['key' => 'tesoreria',         'label' => 'Tesorería',     'sublabel' => 'Por pagar',          'color' => '#0dcaf0', 'valueColor' => '#212529'],
        ['key' => 'autorizacion_pago', 'label' => 'Autorización',  'sublabel' => 'Por autorizar',      'color' => '#0dcaf0', 'valueColor' => '#212529'],
        ['key' => 'verificacion_pago', 'label' => 'Verificación',  'sublabel' => 'Por verificar',      'color' => '#ffc107', 'valueColor' => '#212529'],
        ['key' => 'pagada',            'label' => 'Pagadas',       'sublabel' => 'Completadas',        'color' => '#198754', 'valueColor' => '#198754'],
        ['key' => 'rechazada',         'label' => 'Rechazadas',    'sublabel' => 'Requieren atención', 'color' => '#dc3545', 'valueColor' => '#dc3545'],
    ];
    ?>
    <div class="row g-3 mb-3">
        <?php foreach ($invoiceStatCards as $card): ?>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100"<?= $card['color'] ? ' style="border-top-color:' . $card['color'] . ';"' : '' ?>>
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;"><?= h($card['label']) ?></div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:<?= $card['valueColor'] ?>;"><?= $this->Number->format($invoiceStats[$card['key']] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;"><?= h($card['sublabel']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($invoiceFinancialStats)): ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Monto Pagado</div>
                <div style="font-size:1.5rem;font-weight:700;line-height:1.1;color:var(--primary-color);">$<?= $this->Number->format($invoiceFinancialStats['total_paid'] ?? 0, ['places' => 0]) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">En el período</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:#0dcaf0;">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Monto en Proceso</div>
                <div style="font-size:1.5rem;font-weight:700;line-height:1.1;color:#212529;">$<?= $this->Number->format($invoiceFinancialStats['total_in_process'] ?? 0, ['places' => 0]) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Sin pagar</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--secondary-color);">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Promedio/Factura</div>
                <div style="font-size:1.5rem;font-weight:700;line-height:1.1;color:#212529;">$<?= $this->Number->format($invoiceFinancialStats['avg_amount'] ?? 0, ['places' => 0]) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Media del período</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:#dc3545;">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Vencidas</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#dc3545;"><?= $this->Number->format($invoiceFinancialStats['overdue'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Requieren atención</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($invoiceChartData)): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-5">
            <div class="card card-primary">
                <div class="card-body">
                    <div style="font-size:.63rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#999;margin-bottom:.75rem;">Distribución por estado</div>
                    <div id="invoiceDonutChart"
                         data-chart-donut='<?= json_encode($invoiceChartData['donut_status'] ?? []) ?>'></div>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card card-dark">
                <div class="card-body">
                    <div style="font-size:.63rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#999;margin-bottom:.75rem;">Facturas por mes</div>
                    <div id="invoiceBarChart"
                         data-chart-monthly='<?= json_encode($invoiceChartData['monthly'] ?? []) ?>'></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($recentInvoices)): ?>
    <div class="card card-primary">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span style="font-size:.63rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#999;">Actividad reciente</span>
            <?= $this->Html->link('Ver todas →', ['controller' => 'Invoices', 'action' => 'all'], ['style' => 'font-size:.75rem;color:var(--primary-color);text-decoration:none;font-weight:500;']) ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.82rem;">
                <thead>
                    <tr style="background:#fafafa;">
                        <th class="px-3 py-2" style="color:#999;font-weight:600;font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Nº Factura</th>
                        <th class="px-3 py-2" style="color:#999;font-weight:600;font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Proveedor</th>
                        <th class="px-3 py-2" style="color:#999;font-weight:600;font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Estado</th>
                        <th class="px-3 py-2" style="color:#999;font-weight:600;font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Modificado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentInvoices as $invoice): ?>
                    <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $invoice->id]) ?>">
                        <td class="px-3 py-2" style="border-color:var(--border-color);font-weight:500;">
                            <?= h($invoice->invoice_number ?: '#' . $invoice->id) ?>
                        </td>
                        <td class="px-3 py-2" style="border-color:var(--border-color);color:#495057;">
                            <?= h($invoice->provider->name ?? '—') ?>
                        </td>
                        <td class="px-3 py-2" style="border-color:var(--border-color);">
                            <?php if (($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED): ?>
                                <span class="badge bg-danger">Rechazada</span>
                            <?php else: ?>
                                <span class="badge <?= InvoicePresentation::STATUS_BADGES[$invoice->pipeline_status] ?? 'bg-secondary' ?>">
                                    <?= h(InvoiceConstants::STATUS_LABELS[$invoice->pipeline_status] ?? $invoice->pipeline_status) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2" style="border-color:var(--border-color);color:#6c757d;">
                            <?= $invoice->modified ? $invoice->modified->format('d/m/Y H:i') : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ========================================================
   SECCIÓN: RRHH
   ======================================================== */ ?>
<?php if (!empty($rrhhStats) || !empty($recentNovelties)): ?>
<div>
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-people-fill" style="color:var(--primary-color);font-size:1rem;"></i>
        <span style="font-size:.65rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6c757d;">RRHH</span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>

    <?php if (!empty($rrhhStats)): ?>
    <div class="row g-3 mb-3">
        <?php if (isset($rrhhStats['active_employees'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Empleados</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($rrhhStats['active_employees']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Activos</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($rrhhStats['novelties_month'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--secondary-color);">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Novedades</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($rrhhStats['novelties_month']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Este mes</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($rrhhStats['active_novelties'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <a href="<?= $this->Url->build(['controller' => 'EmployeeNovelties', 'action' => 'active']) ?>" class="text-decoration-none">
                <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--primary-color);cursor:pointer;">
                    <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Vigentes</div>
                    <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:var(--primary-color);"><?= $this->Number->format($rrhhStats['active_novelties']) ?></div>
                    <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Hoy</div>
                </div>
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($rrhhExtendedStats)): ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Edad Media</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $rrhhExtendedStats['avg_age'] ?? 0 ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Años</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--secondary-color);">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Antigüedad Media</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $rrhhExtendedStats['avg_tenure'] ?? 0 ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Años</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--primary-color);">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Nuevos Ingresos</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:var(--primary-color);"><?= $this->Number->format($rrhhExtendedStats['new_hires'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">En el período</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:#dc3545;">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Retiros</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#dc3545;"><?= $this->Number->format($rrhhExtendedStats['terminations'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">En el período</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($rrhhChartData)): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-5">
            <div class="card card-primary">
                <div class="card-body">
                    <div style="font-size:.63rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#999;margin-bottom:.75rem;">Distribución por contrato</div>
                    <div id="employeeDonutChart"
                         data-chart-contract='<?= json_encode($rrhhChartData['donut_contract'] ?? []) ?>'></div>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card card-dark">
                <div class="card-body">
                    <div style="font-size:.63rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#999;margin-bottom:.75rem;">Novedades por mes</div>
                    <div id="noveltyBarChart"
                         data-chart-novelties='<?= json_encode($rrhhChartData['monthly_novelties'] ?? []) ?>'></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($recentNovelties)): ?>
    <div class="card" style="border-top-color:var(--secondary-color);">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span style="font-size:.63rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#999;">Novedades recientes</span>
            <?= $this->Html->link('Ver todas →', ['controller' => 'EmployeeNovelties', 'action' => 'index'], ['style' => 'font-size:.75rem;color:var(--primary-color);text-decoration:none;font-weight:500;']) ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.82rem;">
                <thead>
                    <tr style="background:#fafafa;">
                        <th class="px-3 py-2" style="color:#999;font-weight:600;font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Empleado</th>
                        <th class="px-3 py-2" style="color:#999;font-weight:600;font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Tipo</th>
                        <th class="px-3 py-2" style="color:#999;font-weight:600;font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentNovelties as $novelty): ?>
                    <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id]) ?>">
                        <td class="px-3 py-2" style="border-color:var(--border-color);font-weight:500;">
                            <?= h($novelty->employee->full_name ?? '') ?>
                        </td>
                        <td class="px-3 py-2" style="border-color:var(--border-color);color:#495057;">
                            <?= h($novelty->novelty_type->name ?? '—') ?>
                        </td>
                        <td class="px-3 py-2" style="border-color:var(--border-color);color:#6c757d;">
                            <?= $novelty->created ? $novelty->created->format('d/m/Y') : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ========================================================
   SECCIÓN: CATÁLOGOS
   ======================================================== */ ?>
<?php if (!empty($catalogStats)): ?>
<div>
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-collection" style="color:var(--primary-color);font-size:1rem;"></i>
        <span style="font-size:.65rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6c757d;">Catálogos</span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>
    <div class="row g-3">
        <?php if (isset($catalogStats['providers'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Proveedores</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($catalogStats['providers']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Activos</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($catalogStats['operation_centers'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Ctros. Operación</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($catalogStats['operation_centers']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Registrados</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($catalogStats['expense_types'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Tipos de Gasto</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($catalogStats['expense_types']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Configurados</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($catalogStats['cost_centers'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Ctros. de Costos</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($catalogStats['cost_centers']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Registrados</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php /* ========================================================
   SECCIÓN: ADMINISTRACIÓN
   ======================================================== */ ?>
<?php if (!empty($adminStats)): ?>
<div>
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-shield-lock" style="color:var(--primary-color);font-size:1rem;"></i>
        <span style="font-size:.65rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6c757d;">Administración</span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>
    <div class="row g-3">
        <?php if (isset($adminStats['users'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Usuarios</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($adminStats['users']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Activos</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($adminStats['roles'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Roles</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($adminStats['roles']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Configurados</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div>

<?php $this->Html->script('dashboard-charts', ['block' => 'script']); ?>

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
        <i class="bi bi-receipt" style="color:var(--primary-color);font-size:1rem;" aria-hidden="true"></i>
        <span class="sgi-block-title">Facturación</span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100">
                <div class="sgi-stat-label">Total</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($invoiceStats['total'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Facturas</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--warning-color);">
                <div class="sgi-stat-label">Aprobación</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($invoiceStats['aprobacion'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Pendientes</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--info-color);">
                <div class="sgi-stat-label">Contabilidad</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($invoiceStats['contabilidad'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">En proceso</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:#0d6efd;">
                <div class="sgi-stat-label">Tesorería</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($invoiceStats['tesoreria'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Por pagar</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100">
                <div class="sgi-stat-label">Pagadas</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:var(--primary-color);"><?= $this->Number->format($invoiceStats['pagada'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Completadas</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--danger-color);">
                <div class="sgi-stat-label">Rechazadas</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:var(--danger-color);"><?= $this->Number->format($invoiceStats['rechazada'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Requieren atención</div>
            </div>
        </div>
    </div>

    <?php if (!empty($invoiceFinancialStats)): ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100">
                <div class="sgi-stat-label">Monto Pagado</div>
                <div style="font-size:1.5rem;font-weight:700;line-height:1.1;color:var(--primary-color);">$<?= $this->Number->format($invoiceFinancialStats['total_paid'] ?? 0, ['places' => 0]) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">En el período</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--info-color);">
                <div class="sgi-stat-label">Monto en Proceso</div>
                <div style="font-size:1.5rem;font-weight:700;line-height:1.1;color:#212529;">$<?= $this->Number->format($invoiceFinancialStats['total_in_process'] ?? 0, ['places' => 0]) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Sin pagar</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--secondary-color);">
                <div class="sgi-stat-label">Promedio/Factura</div>
                <div style="font-size:1.5rem;font-weight:700;line-height:1.1;color:#212529;">$<?= $this->Number->format($invoiceFinancialStats['avg_amount'] ?? 0, ['places' => 0]) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Media del período</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--danger-color);">
                <div class="sgi-stat-label">Vencidas</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:var(--danger-color);"><?= $this->Number->format($invoiceFinancialStats['overdue'] ?? 0) ?></div>
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
                    <div class="sgi-section-eyebrow mb-2">Distribución por estado</div>
                    <div id="invoiceDonutChart"
                         data-chart-donut="<?= h(json_encode($invoiceChartData['donut_status'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></div>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card card-dark">
                <div class="card-body">
                    <div class="sgi-section-eyebrow mb-2">Facturas por mes</div>
                    <div id="invoiceBarChart"
                         data-chart-monthly="<?= h(json_encode($invoiceChartData['monthly'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($recentInvoices)): ?>
    <div class="card card-primary">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="sgi-section-eyebrow">Actividad reciente</span>
            <?= $this->Html->link('Ver todas →', ['controller' => 'Invoices', 'action' => 'all'], ['style' => 'font-size:.75rem;color:var(--primary-color);text-decoration:none;font-weight:500;']) ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.82rem;">
                <thead>
                    <tr style="background:var(--bg-muted);">
                        <th class="px-3 py-2 sgi-section-eyebrow" style="letter-spacing:.08em;border-bottom:1px solid var(--border-color);">Nº Factura</th>
                        <th class="px-3 py-2 sgi-section-eyebrow" style="letter-spacing:.08em;border-bottom:1px solid var(--border-color);">Proveedor</th>
                        <th class="px-3 py-2 sgi-section-eyebrow" style="letter-spacing:.08em;border-bottom:1px solid var(--border-color);">Estado</th>
                        <th class="px-3 py-2 sgi-section-eyebrow" style="letter-spacing:.08em;border-bottom:1px solid var(--border-color);">Modificado</th>
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
        <i class="bi bi-people-fill" style="color:var(--primary-color);font-size:1rem;" aria-hidden="true"></i>
        <span class="sgi-block-title">RRHH</span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>

    <?php if (!empty($rrhhStats)): ?>
    <div class="row g-3 mb-3">
        <?php if (isset($rrhhStats['active_employees'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100">
                <div class="sgi-stat-label">Empleados</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($rrhhStats['active_employees']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Activos</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($rrhhStats['novelties_month'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--secondary-color);">
                <div class="sgi-stat-label">Novedades</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($rrhhStats['novelties_month']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Este mes</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($rrhhStats['active_novelties'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <a href="<?= $this->Url->build(['controller' => 'EmployeeNovelties', 'action' => 'active']) ?>" class="text-decoration-none">
                <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--primary-color);cursor:pointer;">
                    <div class="sgi-stat-label">Vigentes</div>
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
                <div class="sgi-stat-label">Edad Media</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $rrhhExtendedStats['avg_age'] ?? 0 ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Años</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--secondary-color);">
                <div class="sgi-stat-label">Antigüedad Media</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $rrhhExtendedStats['avg_tenure'] ?? 0 ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Años</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--primary-color);">
                <div class="sgi-stat-label">Nuevos Ingresos</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:var(--primary-color);"><?= $this->Number->format($rrhhExtendedStats['new_hires'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">En el período</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-3">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--danger-color);">
                <div class="sgi-stat-label">Retiros</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:var(--danger-color);"><?= $this->Number->format($rrhhExtendedStats['terminations'] ?? 0) ?></div>
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
                    <div class="sgi-section-eyebrow mb-2">Distribución por contrato</div>
                    <div id="employeeDonutChart"
                         data-chart-contract="<?= h(json_encode($rrhhChartData['donut_contract'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></div>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card card-dark">
                <div class="card-body">
                    <div class="sgi-section-eyebrow mb-2">Novedades por mes</div>
                    <div id="noveltyBarChart"
                         data-chart-novelties="<?= h(json_encode($rrhhChartData['monthly_novelties'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($recentNovelties)): ?>
    <div class="card" style="border-top-color:var(--secondary-color);">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="sgi-section-eyebrow">Novedades recientes</span>
            <?= $this->Html->link('Ver todas →', ['controller' => 'EmployeeNovelties', 'action' => 'index'], ['style' => 'font-size:.75rem;color:var(--primary-color);text-decoration:none;font-weight:500;']) ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.82rem;">
                <thead>
                    <tr style="background:var(--bg-muted);">
                        <th class="px-3 py-2 sgi-section-eyebrow" style="letter-spacing:.08em;border-bottom:1px solid var(--border-color);">Empleado</th>
                        <th class="px-3 py-2 sgi-section-eyebrow" style="letter-spacing:.08em;border-bottom:1px solid var(--border-color);">Tipo</th>
                        <th class="px-3 py-2 sgi-section-eyebrow" style="letter-spacing:.08em;border-bottom:1px solid var(--border-color);">Fecha</th>
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
        <i class="bi bi-collection" style="color:var(--primary-color);font-size:1rem;" aria-hidden="true"></i>
        <span class="sgi-block-title">Catálogos</span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>
    <div class="row g-3">
        <?php if (isset($catalogStats['providers'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div class="sgi-stat-label">Proveedores</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($catalogStats['providers']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Activos</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($catalogStats['operation_centers'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div class="sgi-stat-label">Ctros. Operación</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($catalogStats['operation_centers']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Registrados</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($catalogStats['expense_types'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div class="sgi-stat-label">Tipos de Gasto</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($catalogStats['expense_types']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Configurados</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($catalogStats['cost_centers'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div class="sgi-stat-label">Ctros. de Costos</div>
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
        <i class="bi bi-shield-lock" style="color:var(--primary-color);font-size:1rem;" aria-hidden="true"></i>
        <span class="sgi-block-title">Administración</span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>
    <div class="row g-3">
        <?php if (isset($adminStats['users'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div class="sgi-stat-label">Usuarios</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($adminStats['users']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Activos</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($adminStats['roles'])): ?>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card accent-dark p-3 h-100">
                <div class="sgi-stat-label">Roles</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($adminStats['roles']) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Configurados</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div>

<?php
$this->Html->script(
    'https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js',
    [
        'block' => 'script',
        'defer' => true,
        'integrity' => 'sha384-KNaFJ+EK516RuHsoycvreec5pD7BkTKJEkjMrVSQWu9KGTl7En4dhIDv7t1DFJ+g',
        'crossorigin' => 'anonymous',
    ],
);
$this->Html->script('dashboard-charts', ['block' => 'script']);
?>

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
 */
$this->assign('title', 'Inicio');
$userPermissions = $userPermissions ?? [];
$canView = fn(string $module): bool => !empty($userPermissions[$module]['can_view']);

$invoiceStats    = $invoiceStats ?? [];
$recentInvoices  = $recentInvoices ?? [];
$rrhhStats       = $rrhhStats ?? [];
$recentNovelties = $recentNovelties ?? [];
$catalogStats    = $catalogStats ?? [];
$adminStats      = $adminStats ?? [];

$dias  = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
          'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$hoy   = new DateTime();
$fecha = $dias[$hoy->format('w')] . ', ' . $hoy->format('j') . ' de ' . $meses[(int)$hoy->format('n') - 1] . ' de ' . $hoy->format('Y');

$statusBadge = [
    'aprobacion'   => ['label' => 'Aprobación',  'class' => 'bg-warning text-dark'],
    'contabilidad' => ['label' => 'Contabilidad', 'class' => 'bg-info text-dark'],
    'tesoreria'    => ['label' => 'Tesorería',    'class' => 'bg-primary'],
    'pagada'       => ['label' => 'Pagada',       'class' => 'bg-success'],
];
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

    <div class="row g-3 mb-3">
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Total</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($invoiceStats['total'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Facturas</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:#ffc107;">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Aprobación</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($invoiceStats['aprobacion'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Pendientes</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:#0dcaf0;">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Contabilidad</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($invoiceStats['contabilidad'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">En proceso</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:#0d6efd;">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Tesorería</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $this->Number->format($invoiceStats['tesoreria'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Por pagar</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Pagadas</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:var(--primary-color);"><?= $this->Number->format($invoiceStats['pagada'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Completadas</div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-xl-2">
            <div class="sgi-stat-card p-3 h-100" style="border-top-color:#dc3545;">
                <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Rechazadas</div>
                <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#dc3545;"><?= $this->Number->format($invoiceStats['rechazada'] ?? 0) ?></div>
                <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Requieren atención</div>
            </div>
        </div>
    </div>

    <?php if (!empty($recentInvoices)): ?>
    <div style="background:#fff;border:1px solid var(--border-color);">
        <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border-color);">
            <span style="font-size:.7rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;">Actividad reciente</span>
            <?= $this->Html->link('Ver todas →', ['controller' => 'Invoices', 'action' => 'all'], ['style' => 'font-size:.78rem;color:var(--primary-color);text-decoration:none;font-weight:500;']) ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.82rem;">
                <thead>
                    <tr style="background:#f8f9fa;">
                        <th class="px-3 py-2" style="color:#6c757d;font-weight:600;font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Nº Factura</th>
                        <th class="px-3 py-2" style="color:#6c757d;font-weight:600;font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Proveedor</th>
                        <th class="px-3 py-2" style="color:#6c757d;font-weight:600;font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Estado</th>
                        <th class="px-3 py-2" style="color:#6c757d;font-weight:600;font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Modificado</th>
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
                            <?php if (($invoice->area_approval ?? '') === 'Rechazada'): ?>
                                <span class="badge bg-danger">Rechazada</span>
                            <?php else: ?>
                                <?php $s = $statusBadge[$invoice->pipeline_status] ?? ['label' => $invoice->pipeline_status, 'class' => 'bg-secondary']; ?>
                                <span class="badge <?= $s['class'] ?>"><?= h($s['label']) ?></span>
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
    </div>
    <?php endif; ?>

    <?php if (!empty($recentNovelties)): ?>
    <div style="background:#fff;border:1px solid var(--border-color);">
        <div class="d-flex align-items-center justify-content-between px-3 py-2" style="border-bottom:1px solid var(--border-color);">
            <span style="font-size:.7rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;">Novedades recientes</span>
            <?= $this->Html->link('Ver todas →', ['controller' => 'EmployeeNovelties', 'action' => 'index'], ['style' => 'font-size:.78rem;color:var(--primary-color);text-decoration:none;font-weight:500;']) ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.82rem;">
                <thead>
                    <tr style="background:#f8f9fa;">
                        <th class="px-3 py-2" style="color:#6c757d;font-weight:600;font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Empleado</th>
                        <th class="px-3 py-2" style="color:#6c757d;font-weight:600;font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Tipo</th>
                        <th class="px-3 py-2" style="color:#6c757d;font-weight:600;font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid var(--border-color);">Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentNovelties as $novelty): ?>
                    <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id]) ?>">
                        <td class="px-3 py-2" style="border-color:var(--border-color);font-weight:500;">
                            <?= h(trim(($novelty->employee->first_name ?? '') . ' ' . ($novelty->employee->last_name ?? ''))) ?>
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

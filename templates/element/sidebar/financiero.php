<?php
/**
 * Sidebar — sección Financiero.
 *
 * @var \App\View\AppView $this
 * @var \Closure $canView    fn(string $module): bool
 * @var \Closure $navLink    fn(string $controller, ?string $action = null): string
 * @var string $currentController                                     (view var del layout)
 * @var array  $sidebarCounters                                        (view var de AppController::_setSidebarCounters)
 * @var int    $rejectedInvoicesCount
 * @var int    $overdueInvoicesCount
 * @var int    $pettyCashMineCount
 * @var int    $pettyCashCount
 * @var int    $refundsMineCount
 * @var int    $refundsCount
 * @var int    $advancesMineCount
 * @var int    $advancesPendingLegalizationCount
 * @var int    $liquidationMineCount
 * @var int    $liquidationRejectedCount
 */

$facturacionItems = array_filter([
    $canView('invoices') ? 'invoices' : null,
    $canView('novelty_liquidation_docs') ? 'novelty_liquidation_docs' : null,
]);
$facturacionSubActive = $this->request->getParam('controller') === 'Invoices';
if (empty($facturacionItems)) {
    return;
}
?>
<li class="nav-heading">Financiero</li>
    <?php if ($canView('invoices')) : ?>
<li class="nav-item sidebar-has-submenu">
    <div class="sidebar-collapsible-header">
        <?= $this->Html->link(
            '<i class="bi bi-receipt-cutoff me-2" aria-hidden="true"></i><span class="flex-grow-1">Todas las Facturas</span>',
            ['controller' => 'Invoices', 'action' => 'all'],
            ['class' => $navLink('Invoices', 'all') . ' flex-grow-1 d-flex align-items-center', 'escape' => false],
        ) ?>
        <button class="sidebar-chevron-btn"
                data-bs-toggle="collapse"
                data-bs-target="#facturacion-submenu"
                aria-expanded="<?= $facturacionSubActive ? 'true' : 'false' ?>"
                aria-controls="facturacion-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $facturacionSubActive ? ' show' : '' ?>" id="facturacion-submenu">
        <ul class="sidebar-submenu">
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-receipt me-2" aria-hidden="true"></i>Mis Facturas' .
                    (!empty($sidebarCounters) ? ' <span class="badge bg-success sidebar-badge ms-auto">' . array_sum($sidebarCounters) . '</span>' : ''),
                    ['controller' => 'Invoices', 'action' => 'index'],
                    ['class' => $navLink('Invoices', 'index') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-x-circle me-2" aria-hidden="true"></i>Rechazadas' .
                    ($rejectedInvoicesCount > 0 ? ' <span class="badge bg-danger sidebar-badge ms-auto">' . $rejectedInvoicesCount . '</span>' : ''),
                    ['controller' => 'Invoices', 'action' => 'rejected'],
                    ['class' => $navLink('Invoices', 'rejected') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-clock-history me-2" aria-hidden="true"></i>Vencidas' .
                    (($overdueInvoicesCount ?? 0) > 0 ? ' <span class="badge bg-danger sidebar-badge ms-auto">' . ($overdueInvoicesCount ?? 0) . '</span>' : ''),
                    ['controller' => 'Invoices', 'action' => 'overdue'],
                    ['class' => $navLink('Invoices', 'overdue') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
            <?php if ($canView('payment_schedulings')) : ?>
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-calendar-check me-2" aria-hidden="true"></i>Programación',
                    ['controller' => 'PaymentSchedulings', 'action' => 'index'],
                    ['class' => $navLink('PaymentSchedulings') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</li>
    <?php endif; ?>
    <?php if ($canView('petty_cash')) : ?>
        <?php $pettyCashSubActive = $currentController === 'PettyCashRecords'; ?>
<li class="nav-item sidebar-has-submenu">
    <div class="sidebar-collapsible-header">
        <?= $this->Html->link(
            '<i class="bi bi-wallet2 me-2" aria-hidden="true"></i><span class="flex-grow-1">Caja Menor</span>',
            ['controller' => 'PettyCashRecords', 'action' => 'all'],
            ['class' => $navLink('PettyCashRecords', 'all') . ' flex-grow-1 d-flex align-items-center', 'escape' => false],
        ) ?>
        <button class="sidebar-chevron-btn"
                data-bs-toggle="collapse"
                data-bs-target="#caja-menor-submenu"
                aria-expanded="<?= $pettyCashSubActive ? 'true' : 'false' ?>"
                aria-controls="caja-menor-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $pettyCashSubActive ? ' show' : '' ?>" id="caja-menor-submenu">
        <ul class="sidebar-submenu">
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-wallet2 me-2" aria-hidden="true"></i>Mis Registros' .
                    ($pettyCashMineCount > 0 ? ' <span class="badge bg-success sidebar-badge ms-auto">' . $pettyCashMineCount . '</span>' : ''),
                    ['controller' => 'PettyCashRecords', 'action' => 'index'],
                    ['class' => $navLink('PettyCashRecords', 'index') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-hourglass-split me-2" aria-hidden="true"></i>Pendientes' .
                    ($pettyCashCount > 0 ? ' <span class="badge bg-warning text-dark sidebar-badge ms-auto">' . $pettyCashCount . '</span>' : ''),
                    ['controller' => 'PettyCashRecords', 'action' => 'pending'],
                    ['class' => $navLink('PettyCashRecords', 'pending') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
        </ul>
    </div>
</li>
    <?php endif; ?>
    <?php if ($canView('refunds')) : ?>
        <?php $refundsSubActive = $currentController === 'Refunds'; ?>
<li class="nav-item sidebar-has-submenu">
    <div class="sidebar-collapsible-header">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-counterclockwise me-2" aria-hidden="true"></i><span class="flex-grow-1">Reintegros</span>',
            ['controller' => 'Refunds', 'action' => 'all'],
            ['class' => $navLink('Refunds', 'all') . ' flex-grow-1 d-flex align-items-center', 'escape' => false],
        ) ?>
        <button class="sidebar-chevron-btn"
                data-bs-toggle="collapse"
                data-bs-target="#refunds-submenu"
                aria-expanded="<?= $refundsSubActive ? 'true' : 'false' ?>"
                aria-controls="refunds-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $refundsSubActive ? ' show' : '' ?>" id="refunds-submenu">
        <ul class="sidebar-submenu">
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-arrow-counterclockwise me-2" aria-hidden="true"></i>Mis Registros' .
                    (($refundsMineCount ?? 0) > 0 ? ' <span class="badge bg-success sidebar-badge ms-auto">' . (int)$refundsMineCount . '</span>' : ''),
                    ['controller' => 'Refunds', 'action' => 'index'],
                    ['class' => $navLink('Refunds', 'index') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-hourglass-split me-2" aria-hidden="true"></i>Pendientes' .
                    (($refundsCount ?? 0) > 0 ? ' <span class="badge bg-warning text-dark sidebar-badge ms-auto">' . (int)$refundsCount . '</span>' : ''),
                    ['controller' => 'Refunds', 'action' => 'pending'],
                    ['class' => $navLink('Refunds', 'pending') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
        </ul>
    </div>
</li>
    <?php endif; ?>
    <?php if ($canView('advances')) : ?>
        <?php $advancesSubActive = $currentController === 'Advances'; ?>
<li class="nav-item sidebar-has-submenu">
    <div class="sidebar-collapsible-header">
        <?= $this->Html->link(
            '<i class="bi bi-cash-coin me-2" aria-hidden="true"></i><span class="flex-grow-1">Anticipos</span>',
            ['controller' => 'Advances', 'action' => 'all'],
            ['class' => $navLink('Advances', 'all') . ' flex-grow-1 d-flex align-items-center', 'escape' => false],
        ) ?>
        <button class="sidebar-chevron-btn"
                data-bs-toggle="collapse"
                data-bs-target="#anticipos-submenu"
                aria-expanded="<?= $advancesSubActive ? 'true' : 'false' ?>"
                aria-controls="anticipos-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $advancesSubActive ? ' show' : '' ?>" id="anticipos-submenu">
        <ul class="sidebar-submenu">
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-cash-coin me-2" aria-hidden="true"></i>Mis Anticipos' .
                    ($advancesMineCount > 0 ? ' <span class="badge bg-success sidebar-badge ms-auto">' . $advancesMineCount . '</span>' : ''),
                    ['controller' => 'Advances', 'action' => 'index'],
                    ['class' => $navLink('Advances', 'index') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-hourglass-split me-2" aria-hidden="true"></i>Pendientes' .
                    (!empty($advancesPendingLegalizationCount) ? ' <span class="badge bg-warning text-dark sidebar-badge ms-auto">' . $advancesPendingLegalizationCount . '</span>' : ''),
                    ['controller' => 'Advances', 'action' => 'pendingLegalization'],
                    ['class' => $navLink('Advances', 'pendingLegalization') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
        </ul>
    </div>
</li>
    <?php endif; ?>
    <?php if ($canView('payment_registry')) : ?>
<li class="nav-item">
        <?= $this->Html->link(
            '<i class="bi bi-cash-stack me-2" aria-hidden="true"></i>Registro de Pagos',
            ['controller' => 'PaymentRegistry', 'action' => 'index'],
            ['class' => $navLink('PaymentRegistry') . ' d-flex align-items-center', 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('novelty_liquidation_docs')) : ?>
        <?php $liquidacionSubActive = $currentController === 'NoveltyLiquidationDocs'; ?>
<li class="nav-item sidebar-has-submenu">
    <div class="sidebar-collapsible-header">
        <?= $this->Html->link(
            '<i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i><span class="flex-grow-1">D. de Liquidación</span>',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'all'],
            ['class' => $navLink('NoveltyLiquidationDocs', 'all') . ' flex-grow-1 d-flex align-items-center', 'escape' => false],
        ) ?>
        <button class="sidebar-chevron-btn"
                data-bs-toggle="collapse"
                data-bs-target="#liquidacion-submenu"
                aria-expanded="<?= $liquidacionSubActive ? 'true' : 'false' ?>"
                aria-controls="liquidacion-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $liquidacionSubActive ? ' show' : '' ?>" id="liquidacion-submenu">
        <ul class="sidebar-submenu">
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i>Mis Documentos' .
                    ($liquidationMineCount > 0 ? ' <span class="badge bg-success sidebar-badge ms-auto">' . $liquidationMineCount . '</span>' : ''),
                    ['controller' => 'NoveltyLiquidationDocs', 'action' => 'index'],
                    ['class' => $navLink('NoveltyLiquidationDocs', 'index') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-x-circle me-2" aria-hidden="true"></i>Rechazadas' .
                    ($liquidationRejectedCount > 0 ? ' <span class="badge bg-danger sidebar-badge ms-auto">' . $liquidationRejectedCount . '</span>' : ''),
                    ['controller' => 'NoveltyLiquidationDocs', 'action' => 'rejected'],
                    ['class' => $navLink('NoveltyLiquidationDocs', 'rejected') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
        </ul>
    </div>
</li>
    <?php endif; ?>

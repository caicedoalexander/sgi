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
<li class="sb-section-head">Financiero</li>
    <?php if ($canView('invoices')) : ?>
<li>
    <div class="sb-collapsible-header">
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-receipt-cutoff" aria-hidden="true"></i></span><span class="grow">Todas las Facturas</span>',
            ['controller' => 'Invoices', 'action' => 'all'],
            ['class' => $navLink('Invoices', 'all'), 'escape' => false],
        ) ?>
        <button class="sb-chevron"
                data-bs-toggle="collapse"
                data-bs-target="#facturacion-submenu"
                aria-expanded="<?= $facturacionSubActive ? 'true' : 'false' ?>"
                aria-controls="facturacion-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $facturacionSubActive ? ' show' : '' ?>" id="facturacion-submenu">
        <ul class="sb-submenu">
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-receipt" aria-hidden="true"></i></span><span class="grow">Mis Facturas</span>' .
                    (!empty($sidebarCounters) ? '<span class="sb-badge is-primary">' . array_sum($sidebarCounters) . '</span>' : ''),
                    ['controller' => 'Invoices', 'action' => 'index'],
                    ['class' => $navLink('Invoices', 'index'), 'escape' => false],
                ) ?>
            </li>
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-x-circle" aria-hidden="true"></i></span><span class="grow">Rechazadas</span>' .
                    ($rejectedInvoicesCount > 0 ? '<span class="sb-badge is-danger">' . $rejectedInvoicesCount . '</span>' : ''),
                    ['controller' => 'Invoices', 'action' => 'rejected'],
                    ['class' => $navLink('Invoices', 'rejected'), 'escape' => false],
                ) ?>
            </li>
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-clock-history" aria-hidden="true"></i></span><span class="grow">Vencidas</span>' .
                    (($overdueInvoicesCount ?? 0) > 0 ? '<span class="sb-badge is-danger">' . ($overdueInvoicesCount ?? 0) . '</span>' : ''),
                    ['controller' => 'Invoices', 'action' => 'overdue'],
                    ['class' => $navLink('Invoices', 'overdue'), 'escape' => false],
                ) ?>
            </li>
            <?php if ($canView('payment_schedulings')) : ?>
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-calendar-check" aria-hidden="true"></i></span><span class="grow">Programación</span>',
                    ['controller' => 'PaymentSchedulings', 'action' => 'index'],
                    ['class' => $navLink('PaymentSchedulings'), 'escape' => false],
                ) ?>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</li>
    <?php endif; ?>
    <?php if ($canView('petty_cash')) : ?>
        <?php $pettyCashSubActive = $currentController === 'PettyCashRecords'; ?>
<li>
    <div class="sb-collapsible-header">
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-wallet2" aria-hidden="true"></i></span><span class="grow">Caja Menor</span>',
            ['controller' => 'PettyCashRecords', 'action' => 'all'],
            ['class' => $navLink('PettyCashRecords', 'all'), 'escape' => false],
        ) ?>
        <button class="sb-chevron"
                data-bs-toggle="collapse"
                data-bs-target="#caja-menor-submenu"
                aria-expanded="<?= $pettyCashSubActive ? 'true' : 'false' ?>"
                aria-controls="caja-menor-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $pettyCashSubActive ? ' show' : '' ?>" id="caja-menor-submenu">
        <ul class="sb-submenu">
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-wallet2" aria-hidden="true"></i></span><span class="grow">Mis Registros</span>' .
                    ($pettyCashMineCount > 0 ? '<span class="sb-badge is-primary">' . $pettyCashMineCount . '</span>' : ''),
                    ['controller' => 'PettyCashRecords', 'action' => 'index'],
                    ['class' => $navLink('PettyCashRecords', 'index'), 'escape' => false],
                ) ?>
            </li>
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span><span class="grow">Pendientes</span>' .
                    ($pettyCashCount > 0 ? '<span class="sb-badge is-warning">' . $pettyCashCount . '</span>' : ''),
                    ['controller' => 'PettyCashRecords', 'action' => 'pending'],
                    ['class' => $navLink('PettyCashRecords', 'pending'), 'escape' => false],
                ) ?>
            </li>
        </ul>
    </div>
</li>
    <?php endif; ?>
    <?php if ($canView('refunds')) : ?>
        <?php $refundsSubActive = $currentController === 'Refunds'; ?>
<li>
    <div class="sb-collapsible-header">
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></span><span class="grow">Reintegros</span>',
            ['controller' => 'Refunds', 'action' => 'all'],
            ['class' => $navLink('Refunds', 'all'), 'escape' => false],
        ) ?>
        <button class="sb-chevron"
                data-bs-toggle="collapse"
                data-bs-target="#refunds-submenu"
                aria-expanded="<?= $refundsSubActive ? 'true' : 'false' ?>"
                aria-controls="refunds-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $refundsSubActive ? ' show' : '' ?>" id="refunds-submenu">
        <ul class="sb-submenu">
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></span><span class="grow">Mis Registros</span>' .
                    (($refundsMineCount ?? 0) > 0 ? '<span class="sb-badge is-primary">' . (int)$refundsMineCount . '</span>' : ''),
                    ['controller' => 'Refunds', 'action' => 'index'],
                    ['class' => $navLink('Refunds', 'index'), 'escape' => false],
                ) ?>
            </li>
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span><span class="grow">Pendientes</span>' .
                    (($refundsCount ?? 0) > 0 ? '<span class="sb-badge is-warning">' . (int)$refundsCount . '</span>' : ''),
                    ['controller' => 'Refunds', 'action' => 'pending'],
                    ['class' => $navLink('Refunds', 'pending'), 'escape' => false],
                ) ?>
            </li>
        </ul>
    </div>
</li>
    <?php endif; ?>
    <?php if ($canView('advances')) : ?>
        <?php $advancesSubActive = $currentController === 'Advances'; ?>
<li>
    <div class="sb-collapsible-header">
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-cash-coin" aria-hidden="true"></i></span><span class="grow">Anticipos</span>',
            ['controller' => 'Advances', 'action' => 'all'],
            ['class' => $navLink('Advances', 'all'), 'escape' => false],
        ) ?>
        <button class="sb-chevron"
                data-bs-toggle="collapse"
                data-bs-target="#anticipos-submenu"
                aria-expanded="<?= $advancesSubActive ? 'true' : 'false' ?>"
                aria-controls="anticipos-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $advancesSubActive ? ' show' : '' ?>" id="anticipos-submenu">
        <ul class="sb-submenu">
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-cash-coin" aria-hidden="true"></i></span><span class="grow">Mis Anticipos</span>' .
                    ($advancesMineCount > 0 ? '<span class="sb-badge is-primary">' . $advancesMineCount . '</span>' : ''),
                    ['controller' => 'Advances', 'action' => 'index'],
                    ['class' => $navLink('Advances', 'index'), 'escape' => false],
                ) ?>
            </li>
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-file-earmark-check" aria-hidden="true"></i></span><span class="grow">Legalizaciones</span>' .
                    (!empty($advancesPendingLegalizationCount) ? '<span class="sb-badge is-warning">' . $advancesPendingLegalizationCount . '</span>' : ''),
                    ['controller' => 'Advances', 'action' => 'pendingLegalization'],
                    ['class' => $navLink('Advances', 'pendingLegalization'), 'escape' => false],
                ) ?>
            </li>
        </ul>
    </div>
</li>
    <?php endif; ?>
    <?php if ($canView('novelty_liquidation_docs')) : ?>
        <?php $liquidacionSubActive = $currentController === 'NoveltyLiquidationDocs'; ?>
<li>
    <div class="sb-collapsible-header">
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span><span class="grow">D. de Liquidación</span>',
            ['controller' => 'NoveltyLiquidationDocs', 'action' => 'all'],
            ['class' => $navLink('NoveltyLiquidationDocs', 'all'), 'escape' => false],
        ) ?>
        <button class="sb-chevron"
                data-bs-toggle="collapse"
                data-bs-target="#liquidacion-submenu"
                aria-expanded="<?= $liquidacionSubActive ? 'true' : 'false' ?>"
                aria-controls="liquidacion-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $liquidacionSubActive ? ' show' : '' ?>" id="liquidacion-submenu">
        <ul class="sb-submenu">
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span><span class="grow">Mis Documentos</span>' .
                    ($liquidationMineCount > 0 ? '<span class="sb-badge is-primary">' . $liquidationMineCount . '</span>' : ''),
                    ['controller' => 'NoveltyLiquidationDocs', 'action' => 'index'],
                    ['class' => $navLink('NoveltyLiquidationDocs', 'index'), 'escape' => false],
                ) ?>
            </li>
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-x-circle" aria-hidden="true"></i></span><span class="grow">Rechazadas</span>' .
                    ($liquidationRejectedCount > 0 ? '<span class="sb-badge is-danger">' . $liquidationRejectedCount . '</span>' : ''),
                    ['controller' => 'NoveltyLiquidationDocs', 'action' => 'rejected'],
                    ['class' => $navLink('NoveltyLiquidationDocs', 'rejected'), 'escape' => false],
                ) ?>
            </li>
        </ul>
    </div>
</li>
    <?php endif; ?>

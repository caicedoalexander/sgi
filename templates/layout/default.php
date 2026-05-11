<?php
/**
 * @var \App\View\AppView $this
 * @var object|null $currentUser
 * @var array $sidebarCounters
 * @var array $userPermissions
 */
$sidebarCounters = $sidebarCounters ?? [];
$currentUser = $currentUser ?? null;
$userPermissions = $userPermissions ?? [];
$currentController = $this->request->getParam('controller');
$rejectedInvoicesCount = $rejectedInvoicesCount ?? 0;
$pettyCashCount = $pettyCashCount ?? 0;
$noveltiesCount = $noveltiesCount ?? 0;
$rejectedNoveltiesCount = $rejectedNoveltiesCount ?? 0;
$advancesPendingLegalizationCount = $advancesPendingLegalizationCount ?? 0;
$liquidationMineCount = $liquidationMineCount ?? 0;
$liquidationRejectedCount = $liquidationRejectedCount ?? 0;
$pettyCashMineCount = $pettyCashMineCount ?? 0;
$advancesMineCount = $advancesMineCount ?? 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->fetch('title') ?> | SGI · COPCSA</title>
    <link rel="icon" type="image/png" href="<?= $this->Url->build('/img/copcsa.png') ?>">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <!-- Bootstrap primero, luego nuestros estilos para poder sobreescribir -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" integrity="sha384-OXVF05DQEe311p6ohU11NwlnX08FzMCsyoXzGOaL+83dKAb3qS17yZJxESl8YrJQ" crossorigin="anonymous">
    <?= $this->Html->css('styles') ?>
    <?php if ($this->request->getAttribute('csrfToken')) : ?>
    <meta name="csrfToken" content="<?= $this->request->getAttribute('csrfToken') ?>">
    <?php endif; ?>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
    <style>
        :root { --sidebar-width: 260px; }
        .sidebar {
            position: fixed;
            top: 0; left: 0; bottom: 0;
            width: max-content;
            min-width: 200px;
            overflow-y: hidden;
            z-index: 1030;
            display: flex;
            flex-direction: column;
        }
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }
        .content-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <nav class="sidebar d-flex flex-column flex-shrink-0 p-3 bg-dark">

            <!-- Logo -->
            <?= $this->element('sgi_logo') ?>

            <!-- Divisor -->
            <div style="height:1px;background:rgba(255,255,255,.07);margin-bottom:.75rem;"></div>

            <?php
            $canView = function (string $module) use ($userPermissions): bool {
                return !empty($userPermissions[$module]['can_view']);
            };
            $currentAction = $this->request->getParam('action');
            $navLink = function (string $controller, ?string $action = null) use ($currentController, $currentAction): string {
                $match = $currentController === $controller;
                if ($action !== null) {
                    $match = $match && $currentAction === $action;
                }

                return 'nav-link' . ($match ? ' active' : '');
            };
            ?>

            <div class="sidebar-nav">
            <ul class="nav nav-pills flex-column mb-3">
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-house-door me-2" aria-hidden="true"></i>Inicio',
                        ['controller' => 'Dashboard', 'action' => 'index'],
                        ['class' => $navLink('Dashboard'), 'escape' => false],
                    ) ?>
                </li>

                <?php
                $facturacionItems = array_filter([
                    $canView('invoices') ? 'invoices' : null,
                    $canView('novelty_liquidation_docs') ? 'novelty_liquidation_docs' : null,
                ]);
                $facturacionSubActive = $this->request->getParam('controller') === 'Invoices';
                if (!empty($facturacionItems)) : ?>
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
                    <div class="collapse<?= $facturacionSubActive ? ' show' : '' ?>"
                         id="facturacion-submenu">
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
                        <?php
                        $liquidacionSubActive = $currentController === 'NoveltyLiquidationDocs';
                        ?>
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
                    <div class="collapse<?= $liquidacionSubActive ? ' show' : '' ?>"
                         id="liquidacion-submenu">
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
                <?php endif; ?>

                <?php
                $rrhhItems = array_filter([
                    $canView('employees') ? 'employees' : null,
                    $canView('employee_novelties') ? 'employee_novelties' : null,
                ]);
                if (!empty($rrhhItems)) : ?>
                <li class="nav-heading">RRHH</li>
                    <?php if ($canView('employees')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-people-fill me-2" aria-hidden="true"></i>Empleados',
                            ['controller' => 'Employees', 'action' => 'index'],
                            ['class' => $navLink('Employees'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php
                    $noveltiesSubActive = $currentController === 'EmployeeNovelties';
                    if ($canView('employee_novelties')) : ?>
                <li class="nav-item sidebar-has-submenu">
                    <div class="sidebar-collapsible-header">
                            <?= $this->Html->link(
                                '<i class="bi bi-journal-text me-2" aria-hidden="true"></i><span class="flex-grow-1">Todas las Novedades</span>',
                                ['controller' => 'EmployeeNovelties', 'action' => 'all'],
                                ['class' => $navLink('EmployeeNovelties', 'all') . ' flex-grow-1 d-flex align-items-center', 'escape' => false],
                            ) ?>
                        <button class="sidebar-chevron-btn"
                                data-bs-toggle="collapse"
                                data-bs-target="#novedades-submenu"
                                aria-expanded="<?= $noveltiesSubActive ? 'true' : 'false' ?>"
                                aria-controls="novedades-submenu">
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="collapse<?= $noveltiesSubActive ? ' show' : '' ?>"
                         id="novedades-submenu">
                        <ul class="sidebar-submenu">
                            <li class="nav-item">
                                <?= $this->Html->link(
                                    '<i class="bi bi-journal-text me-2" aria-hidden="true"></i>Mis Novedades' .
                                    ($noveltiesCount > 0 ? ' <span class="badge bg-warning text-dark sidebar-badge ms-auto">' . $noveltiesCount . '</span>' : ''),
                                    ['controller' => 'EmployeeNovelties', 'action' => 'index'],
                                    ['class' => $navLink('EmployeeNovelties', 'index') . ' d-flex align-items-center', 'escape' => false],
                                ) ?>
                            </li>
                            <li class="nav-item">
                                    <?= $this->Html->link(
                                        '<i class="bi bi-x-circle me-2" aria-hidden="true"></i>Rechazadas' .
                                        ($rejectedNoveltiesCount > 0 ? ' <span class="badge bg-danger sidebar-badge ms-auto">' . $rejectedNoveltiesCount . '</span>' : ''),
                                        ['controller' => 'EmployeeNovelties', 'action' => 'rejected'],
                                        ['class' => $navLink('EmployeeNovelties', 'rejected') . ' d-flex align-items-center', 'escape' => false],
                                    ) ?>
                            </li>
                            <li class="nav-item">
                                    <?= $this->Html->link(
                                        '<i class="bi bi-calendar-check me-2" aria-hidden="true"></i>Vigentes' .
                                        (($activeNoveltiesCount ?? 0) > 0 ? ' <span class="badge bg-success sidebar-badge ms-auto">' . ($activeNoveltiesCount ?? 0) . '</span>' : ''),
                                        ['controller' => 'EmployeeNovelties', 'action' => 'active'],
                                        ['class' => $navLink('EmployeeNovelties', 'active') . ' d-flex align-items-center', 'escape' => false],
                                    ) ?>
                            </li>
                        </ul>
                    </div>
                </li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php
                $catalogoItems = array_filter([
                    $canView('approvers') ? 'approvers' : null,
                    $canView('providers') ? 'providers' : null,
                    $canView('operation_centers') ? 'operation_centers' : null,
                    $canView('expense_types') ? 'expense_types' : null,
                    $canView('cost_centers') ? 'cost_centers' : null,
                    $canView('positions') ? 'positions' : null,
                    $canView('marital_statuses') ? 'marital_statuses' : null,
                    $canView('education_levels') ? 'education_levels' : null,
                    $canView('default_folders') ? 'default_folders' : null,
                    $canView('novelty_types') ? 'novelty_types' : null,
                    $canView('temporary_organizations') ? 'temporary_organizations' : null,
                    $canView('leave_document_templates') ? 'leave_document_templates' : null,
                ]);
                if (!empty($catalogoItems)) : ?>
                <li class="nav-heading">Catálogos</li>
                    <?php if ($canView('approvers')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-person-check me-2" aria-hidden="true"></i>Aprobadores',
                            ['controller' => 'Approvers', 'action' => 'index'],
                            ['class' => $navLink('Approvers'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('providers')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-truck me-2" aria-hidden="true"></i>Proveedores',
                            ['controller' => 'Providers', 'action' => 'index'],
                            ['class' => $navLink('Providers'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('banking_entities')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-bank me-2" aria-hidden="true"></i>Entidades Bancarias',
                            ['controller' => 'BankingEntities', 'action' => 'index'],
                            ['class' => $navLink('BankingEntities'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('operation_centers')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-geo-alt me-2" aria-hidden="true"></i>Centros de Operación',
                            ['controller' => 'OperationCenters', 'action' => 'index'],
                            ['class' => $navLink('OperationCenters'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('expense_types')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-tags me-2" aria-hidden="true"></i>Tipos de Gasto',
                            ['controller' => 'ExpenseTypes', 'action' => 'index'],
                            ['class' => $navLink('ExpenseTypes'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('cost_centers')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-diagram-3 me-2" aria-hidden="true"></i>Centros de Costos',
                            ['controller' => 'CostCenters', 'action' => 'index'],
                            ['class' => $navLink('CostCenters'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('positions')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-briefcase me-2" aria-hidden="true"></i>Cargos',
                            ['controller' => 'Positions', 'action' => 'index'],
                            ['class' => $navLink('Positions'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('marital_statuses')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-heart me-2" aria-hidden="true"></i>Estados Civiles',
                            ['controller' => 'MaritalStatuses', 'action' => 'index'],
                            ['class' => $navLink('MaritalStatuses'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('education_levels')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-mortarboard me-2" aria-hidden="true"></i>Niveles Educativos',
                            ['controller' => 'EducationLevels', 'action' => 'index'],
                            ['class' => $navLink('EducationLevels'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('default_folders')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-folder me-2" aria-hidden="true"></i>Carpetas por Defecto',
                            ['controller' => 'DefaultFolders', 'action' => 'index'],
                            ['class' => $navLink('DefaultFolders'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('novelty_types')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-list-check me-2" aria-hidden="true"></i>Tipos de Novedad',
                            ['controller' => 'NoveltyTypes', 'action' => 'index'],
                            ['class' => $navLink('NoveltyTypes'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('temporary_organizations')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-building-gear me-2" aria-hidden="true"></i>Org. Temporales',
                            ['controller' => 'TemporaryOrganizations', 'action' => 'index'],
                            ['class' => $navLink('TemporaryOrganizations'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('leave_document_templates')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-file-earmark-ruled me-2" aria-hidden="true"></i>Plantillas Documento',
                            ['controller' => 'LeaveDocumentTemplates', 'action' => 'index'],
                            ['class' => $navLink('LeaveDocumentTemplates'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php
                $adminItems = array_filter([
                    $canView('users') ? 'users' : null,
                    $canView('roles') ? 'roles' : null,
                    $canView('system_settings') ? 'system_settings' : null,
                    $canView('email_logs') ? 'email_logs' : null,
                ]);
                if (!empty($adminItems)) : ?>
                <li class="nav-heading">Administración</li>
                    <?php if ($canView('users')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-people me-2" aria-hidden="true"></i>Usuarios',
                            ['controller' => 'Users', 'action' => 'index'],
                            ['class' => $navLink('Users'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('roles')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Roles',
                            ['controller' => 'Roles', 'action' => 'index'],
                            ['class' => $navLink('Roles'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('system_settings')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-gear me-2" aria-hidden="true"></i>Configuración',
                            ['controller' => 'SystemSettings', 'action' => 'index'],
                            ['class' => $navLink('SystemSettings'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                    <?php if ($canView('email_logs')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-envelope-exclamation me-2" aria-hidden="true"></i>Logs de correo',
                            ['controller' => 'EmailLogs', 'action' => 'index'],
                            ['class' => $navLink('EmailLogs'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
            </div>

            <!-- Footer de usuario -->
            <div class="sidebar-footer d-flex align-items-center justify-content-between">
                <?php if ($currentUser) : ?>
                    <div class="d-flex align-items-center" style="min-width:0;">
                        <div class="d-flex align-items-center justify-content-center me-2"
                             style="width:32px;height:32px;background-color:var(--primary-color);flex-shrink:0;">
                            <i class="bi bi-person text-white" style="font-size:.95rem;" aria-hidden="true"></i>
                        </div>
                        <div class="overflow-hidden">
                            <div class="text-white fw-medium text-truncate" style="font-size:.82rem;"><?= h($currentUser->full_name) ?></div>
                            <div style="font-size:.7rem;color:rgba(255,255,255,.35);"><?= h($currentUser->role->name ?? '') ?></div>
                        </div>
                    </div>
                    <?= $this->Html->link(
                        '<i class="bi bi-box-arrow-right" aria-hidden="true"></i>',
                        ['controller' => 'Users', 'action' => 'logout'],
                        ['class' => 'sgi-sidebar-logout', 'escape' => false],
                    ) ?>
                <?php endif; ?>
            </div>
        </nav>
        <script>(function(){var s=document.querySelector('.sidebar');if(s)document.documentElement.style.setProperty('--sidebar-width',s.offsetWidth+'px');})();</script>

        <!-- Contenido -->
        <?php
        $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $dias  = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
        $now   = new DateTime();
        $topbarDate = $dias[(int)$now->format('w')] . ', ' . $now->format('d') . ' de ' . $meses[(int)$now->format('n')] . ' del ' . $now->format('Y');
        ?>
        <div class="content-wrapper flex-grow-1">
            <nav class="sgi-topbar sticky-top d-flex align-items-center justify-content-between px-4">
                <span class="sgi-topbar-title"><?= $this->fetch('title') ?></span>
                <div class="sgi-topbar-date d-none d-md-flex align-items-center gap-2">
                    <i class="bi bi-calendar3" style="font-size:.75rem" aria-hidden="true"></i>
                    <?= $topbarDate ?>
                </div>
            </nav>
            <main class="p-4">
                <?= $this->fetch('content') ?>
            </main>
        </div>
    </div>

    <!-- Flash notifications fijas -->
    <div id="sgi-flash-container">
        <?= $this->Flash->render() ?>
    </div>

    <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/es.js" integrity="sha384-j/aEP2b+3OKmGqank2qCSosSrlrF9jpIpdgApXq2ryJYBpLSbEi63/PDdL+rKmcQ" crossorigin="anonymous"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" integrity="sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs" crossorigin="anonymous"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" integrity="sha384-d3UHjPdzJkZuk5H3qKYMLRyWLAQBJbby2yr2Q58hXXtAGF8RSNO9jpLDlKKPv5v3" crossorigin="anonymous"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/es.js" integrity="sha384-UeeDJLxU9E9sJVeeJ8aoFWAfW2uB0Hggm59b4wtvZl8A1FnbmWnAaH4DlLdlnHKD" crossorigin="anonymous"></script>
    <!-- AutoNumeric y ApexCharts se cargan bajo demanda desde templates específicos
         (ver element/cdn_autonumeric.php y templates/Dashboard/index.php). -->
    <?= $this->Html->script('sgi-dialogs', ['block' => false]) ?>
    <?= $this->Html->script('sgi-common', ['block' => false]) ?>
    <?= $this->fetch('script') ?>
</body>
</html>
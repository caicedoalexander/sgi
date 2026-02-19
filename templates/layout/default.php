<?php
/**
 * @var \App\View\AppView $this
 * @var object|null $currentUser
 * @var array $sidebarCounters
 */
$sidebarCounters = $sidebarCounters ?? [];
$currentUser = $currentUser ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SGI - <?= $this->fetch('title') ?></title>
    <?= $this->Html->meta('icon') ?>
    <?= $this->Html->css('styles') ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
    <style>
        :root { --sidebar-width: 260px; }
        .sidebar {
            position: fixed;
            top: 0; left: 0; bottom: 0;
            width: var(--sidebar-width);
            overflow-y: auto;
            z-index: 100;
            display: flex;
            flex-direction: column;
        }
        .sidebar .nav-link { color: rgba(255,255,255,.75); border-radius: .375rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: #fff;
            background-color: rgba(255,255,255,.1);
        }
        .sidebar .nav-heading {
            color: rgba(255,255,255,.4);
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: .4rem 1rem;
            margin-top: .5rem;
        }
        .sidebar-badge {
            font-size: .75rem;
            padding: .25em .5em;
        }
        .content-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }
        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid rgba(255,255,255,.1);
            padding: .75rem 1rem;
        }
        /* Row click */
        tr.clickable-row { cursor: pointer; transition: background .15s ease; }
        tr.clickable-row:hover { background-color: rgba(13,110,253,.08) !important; }
        /* Flatpickr override */
        .flatpickr-input { background: #fff !important; }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <nav class="sidebar d-flex flex-column flex-shrink-0 p-3 bg-dark">
            <a href="<?= $this->Url->build('/') ?>" class="d-flex align-items-center justify-content-center mb-2 text-white text-decoration-none">
                <div class="rounded-circle d-flex align-items-center justify-content-center me-2" style="width:42px;height:42px;flex-shrink:0;background-color:#469D61;">
                    <i class="bi bi-building fs-4"></i>
                </div>
                <span class="fs-2 fw-bold">SGI</span>
            </a>
            <hr class="text-secondary my-1">
            <ul class="nav nav-pills flex-column">
                <li class="nav-heading">Facturación</li>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-receipt me-2"></i>Facturas' .
                        (!empty($sidebarCounters) ? ' <span class="badge bg-success sidebar-badge ms-auto">' . array_sum($sidebarCounters) . '</span>' : ''),
                        ['controller' => 'Invoices', 'action' => 'index'],
                        ['class' => 'nav-link d-flex align-items-center', 'escape' => false]
                    ) ?>
                </li>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-person-check me-2"></i>Aprobadores',
                        ['controller' => 'Approvers', 'action' => 'index'],
                        ['class' => 'nav-link', 'escape' => false]
                    ) ?>
                </li>
                <li class="nav-heading">Catálogos</li>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-truck me-2"></i>Proveedores',
                        ['controller' => 'Providers', 'action' => 'index'],
                        ['class' => 'nav-link', 'escape' => false]
                    ) ?>
                </li>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-geo-alt me-2"></i>Centros de Operación',
                        ['controller' => 'OperationCenters', 'action' => 'index'],
                        ['class' => 'nav-link', 'escape' => false]
                    ) ?>
                </li>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-tags me-2"></i>Tipos de Gasto',
                        ['controller' => 'ExpenseTypes', 'action' => 'index'],
                        ['class' => 'nav-link', 'escape' => false]
                    ) ?>
                </li>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-diagram-3 me-2"></i>Centros de Costos',
                        ['controller' => 'CostCenters', 'action' => 'index'],
                        ['class' => 'nav-link', 'escape' => false]
                    ) ?>
                </li>
                <li class="nav-heading">Administración</li>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-people me-2"></i>Usuarios',
                        ['controller' => 'Users', 'action' => 'index'],
                        ['class' => 'nav-link', 'escape' => false]
                    ) ?>
                </li>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-shield-lock me-2"></i>Roles',
                        ['controller' => 'Roles', 'action' => 'index'],
                        ['class' => 'nav-link', 'escape' => false]
                    ) ?>
                </li>
            </ul>

            <!-- User info footer -->
            <div class="sidebar-footer d-flex align-items-center justify-content-between">
                <?php if ($currentUser): ?>
                    <div class="d-flex align-items-center text-white-50">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;flex-shrink:0;background-color:#469D61;">
                            <i class="bi bi-person text-white" style="font-size:1.1rem;"></i>
                        </div>
                        <div class="overflow-hidden">
                            <div class="text-white small fw-semibold text-truncate"><?= h($currentUser->full_name) ?></div>
                            <div class="text-white-50" style="font-size:.8rem;"><?= h($currentUser->role->name ?? '') ?></div>
                        </div>
                    </div>
                    <?= $this->Html->link(
                        '<i class="bi bi-box-arrow-right"></i>',
                        ['controller' => 'Users', 'action' => 'logout'],
                        ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]
                    ) ?>
                <?php endif; ?>
            </div>
        </nav>

        <!-- Content -->
        <div class="content-wrapper flex-grow-1 bg-light">
            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-4 sticky-top">
                <span class="navbar-text fw-semibold"><?= $this->fetch('title') ?></span>
            </nav>
            <main class="p-4">
                <?= $this->Flash->render() ?>
                <?= $this->fetch('content') ?>
            </main>
        </div>
    </div>

    <?= $this->element('copcsa') ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/autonumeric@4.10.5/dist/autoNumeric.min.js"></script>
    <?= $this->Html->script('sgi-common', ['block' => false]) ?>
    <?= $this->fetch('script') ?>
</body>
</html>

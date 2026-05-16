<?php
/**
 * @var \App\View\AppView $this
 * @var object|null $currentUser
 * @var array $sidebarCounters
 * @var array $userPermissions
 */
use App\Constants\UploadConstants;

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
    <?php /*
        Preload de fuentes variables WOFF2 (Inter para UI, JetBrains Mono para
        datos cuantitativos: códigos, fechas, montos, IDs). Las dos están
        declaradas como @font-face en styles.css apuntando al WOFF2 sin
        fallback TTF — todos los navegadores modernos soportan WOFF2 desde
        2014. `as="font" crossorigin` son requeridos por la spec.
    */ ?>
    <link rel="preload" href="<?= $this->Url->build('/fonts/Inter-Variable.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= $this->Url->build('/fonts/JetBrainsMono-Variable.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <!-- Bootstrap primero, luego nuestros estilos para poder sobreescribir -->
    <link href="<?= $this->Url->build('/vendor/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= $this->Url->build('/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?= $this->Url->build('/vendor/flatpickr/flatpickr.min.css') ?>">
    <?= $this->Html->css('sgi-flatpickr-overrides') ?>
    <!-- Select2 CSS se carga bajo demanda via element/cdn_select2.php -->
    <?= $this->Html->css('styles') ?>
    <?php if ($this->request->getAttribute('csrfToken')) : ?>
    <meta name="csrfToken" content="<?= $this->request->getAttribute('csrfToken') ?>">
    <?php endif; ?>
    <?php /*
        Tamaño máximo de upload para validación client-side. Fuente única en
        App\Constants\UploadConstants (espejo del límite enforced en backend por
        DocumentUploadTrait y EmployeeDocumentService, y del límite de nginx para
        evitar 413). sgi-common.js consume estos meta en window.SGI_MAX_UPLOAD_BYTES/LABEL.
    */ ?>
    <meta name="sgi-max-upload-bytes" content="<?= UploadConstants::MAX_BYTES ?>">
    <meta name="sgi-max-upload-label" content="<?= h(UploadConstants::MAX_BYTES_LABEL) ?>">
    <?php /*
        Reglas MIME → ícono/color para sgi-document-uploader.js (audit CR-202).
        Fuente única en App\View\Helper\DocumentIconHelper::MIME_RULES; el JS lee
        este JSON para evitar duplicar el match expression en cliente. El JSON está
        escapado con JSON_HEX_TAG/AMP/APOS/QUOT — seguro insertar inline.
    */ ?>
    <script type="application/json" id="sgi-doc-icon-rules"><?= $this->DocumentIcon->rulesJson() ?></script>
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

            <!-- Buscador global -->
            <form method="get" action="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'all']) ?>" class="sgi-sidebar-search mb-2" role="search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="text" name="search" placeholder="Buscar facturas, empleados…" aria-label="Buscar" autocomplete="off">
                <kbd>⌘K</kbd>
            </form>

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

                <?= $this->element('sidebar/financiero', [
                    'canView' => $canView,
                    'navLink' => $navLink,
                    'currentController' => $currentController,
                ]) ?>
                <?= $this->element('sidebar/rrhh', [
                    'canView' => $canView,
                    'navLink' => $navLink,
                    'currentController' => $currentController,
                ]) ?>
                <?= $this->element('sidebar/catalogos', ['canView' => $canView, 'navLink' => $navLink]) ?>
                <?= $this->element('sidebar/administracion', ['canView' => $canView, 'navLink' => $navLink]) ?>
            </ul>
            </div>

            <!-- Footer de usuario -->
            <div class="sidebar-footer d-flex align-items-center justify-content-between">
                <?php if ($currentUser) :
                    $fullName = (string)($currentUser->full_name ?? '');
                    $initials = '';
                    foreach (preg_split('/\s+/', trim($fullName)) ?: [] as $part) {
                        if ($part !== '' && strlen($initials) < 2) {
                            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
                        }
                    }
                    if ($initials === '') { $initials = '·'; }
                ?>
                    <div class="d-flex align-items-center" style="min-width:0;">
                        <div class="d-flex align-items-center justify-content-center me-2 sgi-mono"
                             style="width:32px;height:32px;background-color:var(--primary-color);flex-shrink:0;color:#fff;font-weight:700;font-size:.78rem;letter-spacing:.02em;">
                            <?= h($initials) ?>
                        </div>
                        <div class="overflow-hidden">
                            <div class="text-white fw-medium text-truncate" style="font-size:.82rem;"><?= h($currentUser->full_name) ?></div>
                            <div style="font-size:.7rem;color:rgba(255,255,255,.35);"><?= h($currentUser->role->name ?? '') ?></div>
                        </div>
                    </div>
                    <?= $this->Html->link(
                        '<i class="bi bi-box-arrow-right" aria-hidden="true"></i>',
                        ['controller' => 'Users', 'action' => 'logout'],
                        ['class' => 'sgi-sidebar-logout', 'escape' => false, 'aria-label' => 'Cerrar sesión', 'title' => 'Cerrar sesión'],
                    ) ?>
                <?php endif; ?>
            </div>
        </nav>
        <?php /*
            Sync síncrono de --sidebar-width al parsear el body, antes de que .content-wrapper
            (línea ~616) se renderice. Necesario porque .sidebar usa width:max-content (depende del
            nav-item más largo) y el fallback --sidebar-width:260px del <head> rara vez coincide.
            Sin este sync hay flash de .content-wrapper mal posicionada hasta que ResizeObserver
            dispara su callback inicial post-DOMContentLoaded (sgi-common.js). NO es duplicado de
            sgi-common.js — distintos timings: este es para el primer paint, el ResizeObserver
            para resizes runtime (fuentes, colapso de subnav, resize de ventana).
        */ ?>
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

    <script defer src="<?= $this->Url->build('/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
    <script defer src="<?= $this->Url->build('/vendor/flatpickr/flatpickr.min.js') ?>"></script>
    <script defer src="<?= $this->Url->build('/vendor/flatpickr/l10n/es.js') ?>"></script>
    <!-- jQuery, Select2 JS + i18n se cargan bajo demanda via element/cdn_select2.php.
         AutoNumeric y ApexCharts se cargan bajo demanda desde templates específicos
         (ver element/cdn_autonumeric.php y templates/Dashboard/index.php). -->
    <?= $this->Html->script('sgi-dialogs', ['block' => false]) ?>
    <?= $this->Html->script('sgi-common', ['block' => false]) ?>
    <?= $this->fetch('script') ?>
</body>
</html>
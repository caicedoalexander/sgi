<?php
/**
 * Sidebar — sección Administración.
 *
 * @var \App\View\AppView $this
 * @var \Closure $canView
 * @var \Closure $navLink
 */

$adminItems = array_filter([
    $canView('users') ? 'users' : null,
    $canView('roles') ? 'roles' : null,
    $canView('system_settings') ? 'system_settings' : null,
    $canView('email_logs') ? 'email_logs' : null,
]);
if (empty($adminItems)) {
    return;
}
?>
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

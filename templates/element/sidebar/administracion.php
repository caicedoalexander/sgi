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
<li class="sb-section-head">Administración</li>
    <?php if ($canView('users')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-people" aria-hidden="true"></i></span><span class="grow">Usuarios</span>',
            ['controller' => 'Users', 'action' => 'index'],
            ['class' => $navLink('Users'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('roles')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><span class="grow">Roles</span>',
            ['controller' => 'Roles', 'action' => 'index'],
            ['class' => $navLink('Roles'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('system_settings')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-gear" aria-hidden="true"></i></span><span class="grow">Configuración</span>',
            ['controller' => 'SystemSettings', 'action' => 'index'],
            ['class' => $navLink('SystemSettings'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('email_logs')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-envelope-exclamation" aria-hidden="true"></i></span><span class="grow">Logs de correo</span>',
            ['controller' => 'EmailLogs', 'action' => 'index'],
            ['class' => $navLink('EmailLogs'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>

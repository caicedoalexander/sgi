<?php
/**
 * Sidebar — sección Catálogos.
 *
 * @var \App\View\AppView $this
 * @var \Closure $canView
 * @var \Closure $navLink
 */

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
if (empty($catalogoItems)) {
    return;
}
?>
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

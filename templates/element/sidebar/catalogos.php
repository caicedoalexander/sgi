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
<li class="sb-section-head">Catálogos</li>
    <?php if ($canView('approvers')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-person-check" aria-hidden="true"></i></span><span class="grow">Aprobadores</span>',
            ['controller' => 'Approvers', 'action' => 'index'],
            ['class' => $navLink('Approvers'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('providers')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-truck" aria-hidden="true"></i></span><span class="grow">Proveedores</span>',
            ['controller' => 'Providers', 'action' => 'index'],
            ['class' => $navLink('Providers'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('banking_entities')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-bank" aria-hidden="true"></i></span><span class="grow">Entidades Bancarias</span>',
            ['controller' => 'BankingEntities', 'action' => 'index'],
            ['class' => $navLink('BankingEntities'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('operation_centers')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-geo-alt" aria-hidden="true"></i></span><span class="grow">Centros de Operación</span>',
            ['controller' => 'OperationCenters', 'action' => 'index'],
            ['class' => $navLink('OperationCenters'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('expense_types')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-tags" aria-hidden="true"></i></span><span class="grow">Tipos de Gasto</span>',
            ['controller' => 'ExpenseTypes', 'action' => 'index'],
            ['class' => $navLink('ExpenseTypes'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('cost_centers')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-diagram-3" aria-hidden="true"></i></span><span class="grow">Centros de Costos</span>',
            ['controller' => 'CostCenters', 'action' => 'index'],
            ['class' => $navLink('CostCenters'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('positions')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-briefcase" aria-hidden="true"></i></span><span class="grow">Cargos</span>',
            ['controller' => 'Positions', 'action' => 'index'],
            ['class' => $navLink('Positions'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('marital_statuses')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-heart" aria-hidden="true"></i></span><span class="grow">Estados Civiles</span>',
            ['controller' => 'MaritalStatuses', 'action' => 'index'],
            ['class' => $navLink('MaritalStatuses'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('education_levels')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-mortarboard" aria-hidden="true"></i></span><span class="grow">Niveles Educativos</span>',
            ['controller' => 'EducationLevels', 'action' => 'index'],
            ['class' => $navLink('EducationLevels'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('default_folders')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-folder" aria-hidden="true"></i></span><span class="grow">Carpetas por Defecto</span>',
            ['controller' => 'DefaultFolders', 'action' => 'index'],
            ['class' => $navLink('DefaultFolders'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('novelty_types')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-list-check" aria-hidden="true"></i></span><span class="grow">Tipos de Novedad</span>',
            ['controller' => 'NoveltyTypes', 'action' => 'index'],
            ['class' => $navLink('NoveltyTypes'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('temporary_organizations')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-building-gear" aria-hidden="true"></i></span><span class="grow">Org. Temporales</span>',
            ['controller' => 'TemporaryOrganizations', 'action' => 'index'],
            ['class' => $navLink('TemporaryOrganizations'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php if ($canView('leave_document_templates')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-file-earmark-ruled" aria-hidden="true"></i></span><span class="grow">Plantillas Documento</span>',
            ['controller' => 'LeaveDocumentTemplates', 'action' => 'index'],
            ['class' => $navLink('LeaveDocumentTemplates'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array<string, string> $modules
 * @var array<string, list<string>> $moduleGroups
 * @var array<string, array<string, bool>> $permissionsMatrix
 * @var array<string, array<string, bool>> $pipelineMatrix
 * @var array<string, string> $pipelineLabels
 * @var array<string, array<string, string>> $stepLabels
 * @var int $userCount
 * @var bool $isSystem
 */
$this->assign('title', 'Editar rol: ' . $role->name);
?>
<?= $this->Form->create($role) ?>
<?= $this->element('roles/permissions_editor', [
    'role' => $role,
    'modules' => $modules,
    'moduleGroups' => $moduleGroups,
    'permissionsMatrix' => $permissionsMatrix,
    'pipelineMatrix' => $pipelineMatrix,
    'pipelineLabels' => $pipelineLabels,
    'stepLabels' => $stepLabels,
    'userCount' => $userCount,
    'isSystem' => $isSystem,
]) ?>
<?= $this->Form->end() ?>

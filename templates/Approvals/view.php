<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\ApprovalViewViewModel $viewModel
 */
use App\Constants\ApprovalInboxConstants;

$this->assign('title', $viewModel->pageTitle);

[$statusLabel, $statusClass] = $viewModel->currentStatusBadge;
?>

<?php /* ════════════════════════ HEADER ════════════════════════ */ ?>
<div class="spi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="spi-breadcrumb">
            <?= $this->Html->link('Mis Aprobaciones', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current"><?= h($viewModel->pageTitle) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="spi-page-title"><?= h($viewModel->pageTitle) ?></span>
            <span class="pill <?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?php if ($viewModel->currentStatus === ApprovalInboxConstants::STATUS_PENDING): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-check2-square" aria-hidden="true"></i>Ir a aprobar',
                ['action' => 'approve', $viewModel->module, $viewModel->entity->id],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
        <?php endif; ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-default', 'escape' => false]
        ) ?>
    </div>
</div>

<?php /* ════════════════════════ CONTENIDO ════════════════════════ */ ?>
<div class="spi-card view-anim">

    <?php if ($viewModel->module === ApprovalInboxConstants::MODULE_INVOICE): ?>
        <?= $this->element('approval_detail/invoice', ['entity' => $viewModel->entity]) ?>
    <?php else: ?>
        <?= $this->element('approval_detail/novelty', ['entity' => $viewModel->entity]) ?>
    <?php endif; ?>

    <?php if (!empty($viewModel->observations)): ?>
        <div class="hr"></div>
        <div class="field-row is-last">
            <span class="k">Observaciones</span>
            <span class="v"><?= h($viewModel->observations) ?></span>
        </div>
    <?php endif; ?>

</div>

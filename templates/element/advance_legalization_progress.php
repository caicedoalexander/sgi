<?php
/**
 * Phase 2 progress strip — mirrors element/pipeline_progress.php.
 *
 * @var \App\View\AppView $this
 * @var string $currentStatus
 */
$statuses = \App\Constants\AdvanceConstants::PIPELINE_STATUSES;
$labels = \App\Constants\AdvanceConstants::STATUS_LABELS;
$icons = \App\Constants\AdvanceConstants::STATUS_ICONS;
$currentIndex = array_search($currentStatus, $statuses, true);
if ($currentIndex === false) {
    $currentIndex = 0;
}
?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($statuses as $i => $s): ?>
        <?php $isDone = $i < $currentIndex; $isCurrent = $i === $currentIndex; ?>
        <div class="d-flex align-items-center">
            <span class="badge <?= $isCurrent ? 'bg-primary' : ($isDone ? 'bg-success' : 'bg-light text-muted') ?>">
                <i class="bi <?= h($icons[$s] ?? 'bi-circle') ?> me-1"></i>
                <?= h($labels[$s] ?? $s) ?>
            </span>
            <?php if ($i < count($statuses) - 1): ?>
                <i class="bi bi-arrow-right mx-2 text-muted"></i>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

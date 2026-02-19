<?php
/**
 * @var \App\View\AppView $this
 * @var string $currentStatus
 * @var string[] $pipelineStatuses
 * @var string[] $pipelineLabels
 */
$statusIcons = [
    'revision'     => 'bi-search',
    'area_approved' => 'bi-check-circle',
    'accrued'      => 'bi-calculator',
    'treasury'     => 'bi-bank',
    'paid'         => 'bi-cash-coin',
];
$currentIndex = array_search($currentStatus, $pipelineStatuses);
?>
<div class="pipeline-progress mb-4">
    <div class="d-flex align-items-center justify-content-between position-relative">
        <!-- Connector line -->
        <div class="pipeline-line position-absolute" style="top:24px;left:2.5%;right:2.5%;height:3px;background:#dee2e6;z-index:0;"></div>

        <?php foreach ($pipelineStatuses as $i => $status): ?>
            <?php
                $isPast = $i < $currentIndex;
                $isCurrent = $i === $currentIndex;
                $isFuture = $i > $currentIndex;

                if ($isPast) {
                    $circleClass = 'bg-success text-white';
                    $labelClass = 'text-success fw-semibold';
                } elseif ($isCurrent) {
                    $circleClass = 'bg-primary text-white shadow';
                    $labelClass = 'text-primary fw-bold';
                } else {
                    $circleClass = 'bg-light text-muted border';
                    $labelClass = 'text-muted';
                }
            ?>
            <div class="d-flex flex-column align-items-center position-relative" style="z-index:1;flex:1;">
                <div class="pipeline-step rounded-circle d-flex align-items-center justify-content-center mb-1 <?= $circleClass ?>"
                     style="width:48px;height:48px;font-size:1.1rem;transition:all .3s ease;">
                    <?php if ($isPast): ?>
                        <i class="bi bi-check-lg"></i>
                    <?php else: ?>
                        <i class="bi <?= $statusIcons[$status] ?? 'bi-circle' ?>"></i>
                    <?php endif; ?>
                </div>
                <small class="<?= $labelClass ?> text-center" style="font-size:.7rem;white-space:nowrap;">
                    <?= h($pipelineLabels[$status] ?? $status) ?>
                </small>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var string $status
 */
use App\Constants\LegalizationConstants;

$statuses = LegalizationConstants::STATUSES;
$labels = LegalizationConstants::STATUS_LABELS;
$icons = LegalizationConstants::STATUS_ICONS;

$currentIndex = array_search($status, $statuses);
$totalSteps = count($statuses);
$progressPercent = $totalSteps > 0 ? ($currentIndex / $totalSteps) * 100 : 0;
?>
<div class="pipeline-progress">
    <div class="d-flex align-items-center justify-content-between position-relative">
        <div class="position-absolute"
             style="top:24px;left:12.5%;right:12.5%;height:3px;background:#dee2e6;z-index:0;"></div>
        <div class="position-absolute"
             style="top:24px;left:12.5%;width:<?= $progressPercent ?>%;height:3px;background:var(--primary-color);z-index:0;transition:width .5s ease;"></div>

        <?php foreach ($statuses as $i => $s): ?>
            <?php
                $isPast = $i < $currentIndex;
                $isCurrent = $i === $currentIndex;

                if ($isPast) {
                    $circleStyle = 'background:var(--primary-color);color:#fff;border:2px solid var(--primary-color);';
                    $labelClass = 'fw-semibold';
                    $labelStyle = 'color:var(--primary-color);';
                } elseif ($isCurrent) {
                    $circleStyle = 'background:var(--primary-color);color:#fff;border:2px solid var(--primary-color);';
                    $labelClass = 'fw-bold';
                    $labelStyle = 'color:var(--primary-color);';
                } else {
                    $circleStyle = 'background:#fff;color:#aaa;border:2px solid #dee2e6;';
                    $labelClass = 'text-muted';
                    $labelStyle = '';
                }
            ?>
            <div class="d-flex flex-column align-items-center position-relative" style="z-index:1;flex:1;">
                <div class="d-flex align-items-center justify-content-center mb-1"
                     style="width:48px;height:48px;font-size:1.1rem;transition:all .3s ease;<?= $circleStyle ?>">
                    <?php if ($isPast): ?>
                        <i class="bi bi-check-lg"></i>
                    <?php else: ?>
                        <i class="bi <?= $icons[$s] ?? 'bi-circle' ?>"></i>
                    <?php endif; ?>
                </div>
                <small class="<?= $labelClass ?> text-center" style="font-size:.7rem;white-space:nowrap;<?= $labelStyle ?>">
                    <?= h($labels[$s] ?? $s) ?>
                </small>
            </div>
        <?php endforeach; ?>
    </div>
</div>

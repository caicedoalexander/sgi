<?php
/**
 * @var \App\View\AppView $this
 * @var string $status
 * @var bool $isRejected (optional)
 */
use App\Constants\PettyCashConstants;
use App\View\Presentation\PettyCashPresentation;

$statuses = PettyCashConstants::STATUSES;
$labels = PettyCashConstants::STATUS_LABELS;
$icons = PettyCashPresentation::STATUS_ICONS;
$isRejected = $isRejected ?? false;

$currentIndex = array_search($status, $statuses);
?>
<div class="pipeline-progress">
    <div class="d-flex align-items-center justify-content-between">
        <?php foreach ($statuses as $i => $s): ?>
        <?php
            $isPast    = $i < $currentIndex;
            $isCurrent = $i === $currentIndex;
            $isFuture  = $i > $currentIndex;
            $rejectedHere = $isRejected && $isCurrent;

            if ($rejectedHere) {
                $borderColor = '#dc3545';
                $bgColor     = '#dc3545';
                $iconColor   = '#fff';
                $labelColor  = '#dc3545';
                $labelWeight = '700';
            } elseif ($isPast) {
                $borderColor = 'var(--primary-color)';
                $bgColor     = 'var(--primary-color)';
                $iconColor   = '#fff';
                $labelColor  = 'var(--primary-color)';
                $labelWeight = '500';
            } elseif ($isCurrent) {
                $borderColor = 'var(--primary-color)';
                $bgColor     = 'var(--primary-color)';
                $iconColor   = '#fff';
                $labelColor  = '#111';
                $labelWeight = '700';
            } else {
                $borderColor = '#ddd';
                $bgColor     = '#fff';
                $iconColor   = '#bbb';
                $labelColor  = '#aaa';
                $labelWeight = '500';
            }

            if ($isRejected && $isFuture) {
                $borderColor = 'rgba(220,53,69,.25)';
                $bgColor     = '#fff';
                $iconColor   = '#ccc';
                $labelColor  = '#ccc';
            }

            $opacity = (!$isCurrent && !$isPast && !$isRejected) ? 'opacity:.4;' : '';
        ?>
        <div class="d-flex align-items-center gap-2" style="<?= $opacity ?>">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:32px;height:32px;border:2px solid <?= $borderColor ?>;background:<?= $bgColor ?>;color:<?= $iconColor ?>;font-size:.85rem;transition:all .3s ease;">
                <?php if ($rejectedHere): ?>
                    <i class="bi bi-x-lg fw-bold"></i>
                <?php elseif ($isPast): ?>
                    <i class="bi bi-check-lg"></i>
                <?php else: ?>
                    <i class="bi <?= $icons[$s] ?? 'bi-circle' ?>"></i>
                <?php endif; ?>
            </div>
            <span style="font-size:.75rem;font-weight:<?= $labelWeight ?>;color:<?= $labelColor ?>;white-space:nowrap;">
                <?= h($labels[$s] ?? $s) ?>
            </span>
        </div>
        <?php if ($i < count($statuses) - 1): ?>
        <div style="flex:1;height:2px;margin:0 .75rem;background:<?= $isPast ? ($isRejected ? '#dc3545' : 'var(--primary-color)') : '#e0e0e0' ?>;transition:background .3s ease;"></div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($isRejected): ?>
    <div class="alert alert-danger mt-3 py-2 mb-0 d-flex align-items-center gap-2">
        <i class="bi bi-x-circle-fill fs-5"></i>
        <span><strong>Flujo terminado:</strong> Este registro fue rechazado y no puede avanzar.</span>
    </div>
    <?php endif; ?>
</div>

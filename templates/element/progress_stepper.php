<?php
/**
 * Stepper genérico de pipeline. Sustituye a los 3 elements duplicados
 * (pipeline_progress, petty_cash_progress, refund_progress).
 *
 * @var \App\View\AppView $this
 * @var array<int,string>     $statuses        Slugs ordenados del pipeline.
 * @var array<string,string>  $labels          status_slug → label visible.
 * @var array<string,string>  $icons           status_slug → clase bi-* (default bi-circle).
 * @var string                $currentStatus   Estado actual.
 * @var bool                  $isRejected      (opcional) true → flujo terminado/rechazado.
 * @var array<string,array{label:string,class:string}> $extraBadges
 *     (opcional) status_slug → ['label' => 'Pago Parcial', 'class' => 'bg-warning text-dark']
 *     Se renderiza debajo del label cuando $status coincide con el currentStatus.
 */

$statuses     = $statuses     ?? [];
$labels       = $labels       ?? [];
$icons        = $icons        ?? [];
$isRejected   = $isRejected   ?? false;
$extraBadges  = $extraBadges  ?? [];

$currentIndex = array_search($currentStatus ?? '', $statuses, true);
if ($currentIndex === false) {
    $currentIndex = 0;
}
?>
<div class="pipeline-progress">
    <div class="d-flex align-items-center justify-content-between">
        <?php foreach ($statuses as $i => $s): ?>
        <?php
            $isPast    = $i < $currentIndex;
            $isCurrent = $i === $currentIndex;
            $isFuture  = $i > $currentIndex;
            $rejectedHere = $isRejected && $isCurrent;
            $icon = $icons[$s] ?? 'bi-circle';

            if ($rejectedHere) {
                $borderColor = 'var(--danger-color)';
                $bgColor     = 'var(--danger-color)';
                $iconColor   = '#fff';
                $labelColor  = 'var(--danger-color)';
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
                $labelWeight = '500';
            }

            $opacity = (!$isCurrent && !$isPast && !$isRejected) ? 'opacity:.4;' : '';
            $badge   = ($isCurrent && isset($extraBadges[$s])) ? $extraBadges[$s] : null;
        ?>
        <div class="d-flex align-items-center gap-2" style="<?= $opacity ?>">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:32px;height:32px;border:2px solid <?= $borderColor ?>;background:<?= $bgColor ?>;color:<?= $iconColor ?>;font-size:.85rem;transition:all .3s ease;">
                <?php if ($rejectedHere): ?>
                    <i class="bi bi-x-lg fw-bold" aria-hidden="true"></i>
                <?php elseif ($isPast): ?>
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                <?php else: ?>
                    <i class="bi <?= h($icon) ?>" aria-hidden="true"></i>
                <?php endif; ?>
            </div>
            <div>
                <span style="font-size:.75rem;font-weight:<?= $labelWeight ?>;color:<?= $labelColor ?>;white-space:nowrap;">
                    <?= h($labels[$s] ?? $s) ?>
                </span>
                <?php if ($badge): ?>
                    <br><span class="badge <?= h($badge['class']) ?>" style="font-size:.55rem;"><?= h($badge['label']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($i < count($statuses) - 1): ?>
        <div style="flex:1;height:2px;margin:0 .75rem;background:<?= $isPast ? ($isRejected ? 'var(--danger-color)' : 'var(--primary-color)') : '#e0e0e0' ?>;transition:background .3s ease;"></div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($isRejected): ?>
    <div class="alert alert-danger mt-3 py-2 mb-0 d-flex align-items-center gap-2">
        <i class="bi bi-x-circle-fill fs-5" aria-hidden="true"></i>
        <span><strong>Flujo terminado:</strong> Este registro fue rechazado y no puede avanzar.</span>
    </div>
    <?php endif; ?>
</div>

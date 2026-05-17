<?php
/**
 * Stepper genérico de pipeline. Sustituye a los 3 elements duplicados
 * (pipeline_progress, petty_cash_progress, refund_progress).
 *
 * Los estilos viven en webroot/css/styles.css sección "Componentes — Pipeline Stepper".
 * El template solo computa data-state="past|current|future" + data-rejected="true" en el contenedor.
 *
 * @var \App\View\AppView $this
 * @var array<int,string>     $statuses        Slugs ordenados del pipeline.
 * @var array<string,string>  $labels          status_slug → label visible.
 * @var array<string,string>  $icons           status_slug → clase bi-* (default bi-circle).
 * @var string                $currentStatus   Estado actual.
 * @var bool                  $isRejected      (opcional) true → flujo terminado/rechazado.
 * @var array<string,array{label:string,class:string}> $extraBadges
 *     (opcional) status_slug → ['label' => 'Pago Parcial', 'class' => 'pill-warning-soft']
 *     Se renderiza debajo del label cuando $status coincide con el currentStatus.
 */

$statuses = $statuses ?? [];
$labels = $labels ?? [];
$icons = $icons ?? [];
$isRejected = $isRejected ?? false;
$extraBadges = $extraBadges ?? [];

$currentIndex = array_search($currentStatus ?? '', $statuses, true);
if ($currentIndex === false) {
    $currentIndex = 0;
}
?>
<div class="pipeline-progress">
    <div class="sgi-stepper"<?= $isRejected ? ' data-rejected="true"' : '' ?>>
        <?php foreach ($statuses as $i => $s) : ?>
            <?php
            $state = $i < $currentIndex ? 'past' : ($i === $currentIndex ? 'current' : 'future');
            $icon = $icons[$s] ?? 'bi-circle';
            $badge = $state === 'current' && isset($extraBadges[$s]) ? $extraBadges[$s] : null;
            ?>
        <div class="sgi-step" data-state="<?= $state ?>">
            <div class="sgi-step-circle">
                <?php if ($state === 'current' && $isRejected) : ?>
                    <i class="bi bi-x-lg fw-bold" aria-hidden="true"></i>
                <?php elseif ($state === 'past') : ?>
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                <?php else : ?>
                    <i class="bi <?= h($icon) ?>" aria-hidden="true"></i>
                <?php endif; ?>
            </div>
            <div>
                <span class="sgi-step-label"><?= h($labels[$s] ?? $s) ?></span>
                <?php if ($badge) : ?>
                    <br><span class="pill <?= h($badge['class']) ?>" style="font-size:.55rem;">
                        <?= h($badge['label']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
            <?php if ($i < count($statuses) - 1) : ?>
            <div class="sgi-step-connector" data-state="<?= $i < $currentIndex ? 'past' : 'future' ?>"></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($isRejected) : ?>
    <div class="alert alert-danger mt-3 py-2 mb-0 d-flex align-items-center gap-2">
        <i class="bi bi-x-circle-fill fs-5" aria-hidden="true"></i>
        <span><strong>Flujo terminado:</strong> Este registro fue rechazado y no puede avanzar.</span>
    </div>
    <?php endif; ?>
</div>

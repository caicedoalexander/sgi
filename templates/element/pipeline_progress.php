<?php
/**
 * @var \App\View\AppView $this
 * @var string $currentStatus
 * @var string[] $pipelineStatuses
 * @var string[] $pipelineLabels
 * @var bool $isRejected      (optional) true when area_approval = 'Rechazada'
 * @var string|null $paymentStatus  (optional) invoice payment_status value
 */
$isRejected   = $isRejected ?? false;
$paymentStatus = $paymentStatus ?? null;

$statusIcons = [
    'registro'      => 'bi-search',
    'aprobacion'    => 'bi-check-circle',
    'contabilidad'  => 'bi-calculator',
    'tesoreria'     => 'bi-bank',
    'pagada'        => 'bi-cash-coin',
];
$currentIndex = array_search($currentStatus, $pipelineStatuses);
$isPartialPayment = ($currentStatus === 'tesoreria' && $paymentStatus === 'Pago Parcial');
?>
<?php
$totalSteps = count($pipelineStatuses);
$progressPercent = $totalSteps > 1 ? ($currentIndex / ($totalSteps - 1)) * 100 : 0;
$progressColor = $isRejected ? '#dc3545' : 'var(--primary-color)';
?>
<div class="pipeline-progress">
    <div class="d-flex align-items-center justify-content-between position-relative">
        <!-- Base line (gray) -->
        <div class="position-absolute"
             style="top:24px;left:2.5%;right:2.5%;height:3px;background:#dee2e6;z-index:0;"></div>
        <!-- Progress line (colored) -->
        <div class="position-absolute"
             style="top:24px;left:2.5%;width:<?= $progressPercent * 0.95 ?>%;height:3px;background:<?= $progressColor ?>;z-index:0;transition:width .5s ease,background .3s ease;"></div>

        <?php foreach ($pipelineStatuses as $i => $status): ?>
            <?php
                $isPast    = $i < $currentIndex;
                $isCurrent = $i === $currentIndex;
                $isFuture  = $i > $currentIndex;
                $rejectedHere = $isRejected && $isCurrent;

                if ($rejectedHere) {
                    $circleStyle = 'background:#dc3545;color:#fff;border:2px solid #dc3545;';
                    $labelClass  = 'text-danger fw-bold';
                } elseif ($isPast) {
                    $circleStyle = 'background:var(--primary-color);color:#fff;border:2px solid var(--primary-color);';
                    $labelClass  = 'fw-semibold';
                    $labelStyle  = 'color:var(--primary-color);';
                } elseif ($isCurrent) {
                    $circleStyle = 'background:var(--primary-color);color:#fff;border:2px solid var(--primary-color);';
                    $labelClass  = 'fw-bold';
                    $labelStyle  = 'color:var(--primary-color);';
                } else {
                    $circleStyle = 'background:#fff;color:#aaa;border:2px solid #dee2e6;';
                    $labelClass  = 'text-muted';
                    $labelStyle  = '';
                }
                if ($isRejected && $isFuture) {
                    $circleStyle = 'background:#fff;color:#ccc;border:2px solid rgba(220,53,69,.25);';
                    $labelClass  = 'text-muted';
                    $labelStyle  = 'opacity:.5;';
                }
                if (!isset($labelStyle)) {
                    $labelStyle = '';
                }
            ?>
            <div class="d-flex flex-column align-items-center position-relative" style="z-index:1;flex:1;">
                <div class="d-flex align-items-center justify-content-center mb-1"
                     style="width:48px;height:48px;font-size:1.1rem;transition:all .3s ease;<?= $circleStyle ?>">
                    <?php if ($rejectedHere): ?>
                        <i class="bi bi-x-lg fw-bold"></i>
                    <?php elseif ($isPast): ?>
                        <i class="bi bi-check-lg"></i>
                    <?php else: ?>
                        <i class="bi <?= $statusIcons[$status] ?? 'bi-circle' ?>"></i>
                    <?php endif; ?>
                </div>

                <small class="<?= $labelClass ?> text-center" style="font-size:.7rem;white-space:nowrap;<?= $labelStyle ?>">
                    <?= h($pipelineLabels[$status] ?? $status) ?>
                </small>

                <?php if ($isCurrent && $isRejected): ?>
                    <span class="badge bg-danger mt-1" style="font-size:.6rem;">Rechazada</span>
                <?php elseif ($status === 'tesoreria' && $isPartialPayment): ?>
                    <span class="badge bg-warning text-dark mt-1" style="font-size:.6rem;">Pago Parcial</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($isRejected): ?>
    <div class="alert alert-danger mt-3 py-2 mb-0 d-flex align-items-center gap-2">
        <i class="bi bi-x-circle-fill fs-5"></i>
        <span><strong>Flujo terminado:</strong> Esta factura fue rechazada en la etapa de Registro y no puede avanzar.</span>
    </div>
    <?php endif; ?>
</div>

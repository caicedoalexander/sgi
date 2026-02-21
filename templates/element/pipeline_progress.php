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
    'revision'     => 'bi-search',
    'area_approved' => 'bi-check-circle',
    'accrued'      => 'bi-calculator',
    'treasury'     => 'bi-bank',
    'paid'         => 'bi-cash-coin',
];
$currentIndex = array_search($currentStatus, $pipelineStatuses);
$isPartialPayment = ($currentStatus === 'treasury' && $paymentStatus === 'Pago Parcial');
?>
<div class="pipeline-progress">
    <div class="d-flex align-items-center justify-content-between position-relative">
        <!-- Connector line base -->
        <div class="pipeline-line position-absolute"
             style="top:24px;left:2.5%;right:2.5%;height:3px;background:#dee2e6;z-index:0;"></div>

        <?php foreach ($pipelineStatuses as $i => $status): ?>
            <?php
                $isPast    = $i < $currentIndex;
                $isCurrent = $i === $currentIndex;
                $isFuture  = $i > $currentIndex;

                // Rejection: current step turns red, future steps gray-dashed
                $rejectedHere = $isRejected && $isCurrent;

                if ($rejectedHere) {
                    $circleClass = 'bg-danger text-white shadow';
                    $labelClass  = 'text-danger fw-bold';
                } elseif ($isPast) {
                    $circleClass = 'bg-success text-white';
                    $labelClass  = 'text-success fw-semibold';
                } elseif ($isCurrent) {
                    $circleClass = 'bg-primary text-white shadow';
                    $labelClass  = 'text-primary fw-bold';
                } else {
                    $circleClass = 'bg-light text-muted border';
                    $labelClass  = 'text-muted';
                }
                // When rejected, future steps get a lighter muted style
                if ($isRejected && $isFuture) {
                    $circleClass = 'bg-light text-muted border border-danger border-opacity-25';
                    $labelClass  = 'text-muted opacity-50';
                }
            ?>
            <div class="d-flex flex-column align-items-center position-relative" style="z-index:1;flex:1;">
                <div class="pipeline-step rounded-circle d-flex align-items-center justify-content-center mb-1 <?= $circleClass ?>"
                     style="width:48px;height:48px;font-size:1.1rem;transition:all .3s ease;">
                    <?php if ($rejectedHere): ?>
                        <i class="bi bi-x-lg fw-bold"></i>
                    <?php elseif ($isPast): ?>
                        <i class="bi bi-check-lg"></i>
                    <?php else: ?>
                        <i class="bi <?= $statusIcons[$status] ?? 'bi-circle' ?>"></i>
                    <?php endif; ?>
                </div>

                <small class="<?= $labelClass ?> text-center" style="font-size:.7rem;white-space:nowrap;">
                    <?= h($pipelineLabels[$status] ?? $status) ?>
                </small>

                <?php if ($isCurrent && $isRejected): ?>
                    <span class="badge bg-danger mt-1" style="font-size:.6rem;">Rechazada</span>
                <?php elseif ($status === 'treasury' && $isPartialPayment): ?>
                    <span class="badge bg-warning text-dark mt-1" style="font-size:.6rem;">Pago Parcial</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($isRejected): ?>
    <div class="alert alert-danger mt-3 py-2 mb-0 d-flex align-items-center gap-2">
        <i class="bi bi-x-circle-fill fs-5"></i>
        <span><strong>Flujo terminado:</strong> Esta factura fue rechazada en la etapa de Revisión y no puede avanzar.</span>
    </div>
    <?php endif; ?>
</div>

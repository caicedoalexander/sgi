<?php
/**
 * @var \App\View\AppView $this
 * @var string $currentStatus
 * @var string[] $pipelineStatuses
 * @var string[] $pipelineLabels
 * @var bool $isRejected      (optional) true when area_approval = 'Rechazada'
 * @var bool $isApproved      (optional) true when area_approval = 'Aprobada' and still in aprobacion
 * @var string|null $paymentStatus  (optional) invoice payment_status value
 * @var array $timeline  (optional) map status => ['user_name' => ..., 'date' => ...]
 */

use App\Constants\InvoiceConstants;
use App\Service\InvoicePipelineService;
$isRejected    = $isRejected ?? false;
$isApproved    = $isApproved ?? false;
$paymentStatus = $paymentStatus ?? null;
$timeline      = $timeline ?? [];

$statusIcons  = $statusIcons ?? InvoicePipelineService::STATUS_ICONS;
$currentIndex = array_search($currentStatus, $pipelineStatuses);
$isPartialPayment = ($currentStatus === InvoiceConstants::STATUS_TESORERIA && $paymentStatus === InvoiceConstants::PAYMENT_PARTIAL);
?>
<div class="pipeline-progress">
    <div class="d-flex align-items-center justify-content-between">
        <?php foreach ($pipelineStatuses as $i => $status): ?>
        <?php
            $isPast    = $i < $currentIndex;
            $isCurrent = $i === $currentIndex;
            $isFuture  = $i > $currentIndex;
            $rejectedHere = $isRejected && $isCurrent;
            $icon = $statusIcons[$status] ?? 'bi-circle';

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
                $labelWeight = '500';
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
                    <i class="bi <?= $icon ?>"></i>
                <?php endif; ?>
            </div>
            <div>
                <span style="font-size:.75rem;font-weight:<?= $labelWeight ?>;color:<?= $labelColor ?>;white-space:nowrap;">
                    <?= h($pipelineLabels[$status] ?? $status) ?>
                </span>
                <?php if ($isCurrent && $isRejected): ?>
                    <br><span class="badge bg-danger" style="font-size:.55rem;">Rechazada</span>
                <?php elseif ($isCurrent && $isApproved): ?>
                    <br><span class="badge bg-success" style="font-size:.55rem;">Aprobada</span>
                <?php elseif ($status === InvoiceConstants::STATUS_TESORERIA && $isPartialPayment): ?>
                    <br><span class="badge bg-warning text-dark" style="font-size:.55rem;">Pago Parcial</span>
                <?php endif; ?>
                <?php if (!empty($timeline[$status])): ?>
                    <br><small style="font-size:.55rem;color:#777;line-height:1.1;display:block;">
                        <?= h($timeline[$status]['user_name'] ?? '') ?><?php if (!empty($timeline[$status]['date'])): ?><br><?= h($timeline[$status]['date']) ?><?php endif; ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($i < count($pipelineStatuses) - 1): ?>
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

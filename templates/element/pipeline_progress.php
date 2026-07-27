<?php
/**
 * Wrapper específico de facturas sobre element/progress_stepper.
 *
 * @var \App\View\AppView $this
 * @var string $currentStatus
 * @var string[] $pipelineStatuses
 * @var string[] $pipelineLabels
 * @var bool $isRejected      (optional) true when area_approval = 'Rechazada'
 * @var bool $isApproved      (optional) true when area_approval = 'Aprobada' and still in aprobacion
 * @var string|null $paymentStatus  (optional) invoice payment_status value
 */

use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

$isRejected    = $isRejected ?? false;
$isApproved    = $isApproved ?? false;
$paymentStatus = $paymentStatus ?? null;
$statusIcons   = $statusIcons ?? InvoicePresentation::STATUS_ICONS;

$extraBadges = [];
$current = $currentStatus ?? '';
if ($isRejected) {
    $extraBadges[$current] = ['label' => 'Rechazada', 'class' => 'pill-danger-soft'];
} elseif ($isApproved) {
    $extraBadges[$current] = ['label' => 'Aprobada', 'class' => 'pill-primary-soft'];
} elseif ($current === InvoiceConstants::STATUS_TESORERIA && $paymentStatus === InvoiceConstants::PAYMENT_PARTIAL) {
    $extraBadges[InvoiceConstants::STATUS_TESORERIA] = ['label' => 'Pago Parcial', 'class' => 'pill-warning-soft'];
}

echo $this->element('progress_stepper', [
    'statuses'      => $pipelineStatuses,
    'labels'        => $pipelineLabels,
    'icons'         => $statusIcons,
    'currentStatus' => $current,
    'isRejected'    => $isRejected,
    'extraBadges'   => $extraBadges,
]);

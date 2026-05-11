<?php
/**
 * Wrapper específico de reintegros sobre element/progress_stepper.
 *
 * @var \App\View\AppView $this
 * @var string $status
 * @var bool $isRejected (optional)
 */
use App\Constants\RefundConstants;
use App\View\Presentation\RefundPresentation;

echo $this->element('progress_stepper', [
    'statuses'      => RefundConstants::STATUSES,
    'labels'        => RefundConstants::STATUS_LABELS,
    'icons'         => RefundPresentation::STATUS_ICONS,
    'currentStatus' => $status ?? '',
    'isRejected'    => $isRejected ?? false,
]);

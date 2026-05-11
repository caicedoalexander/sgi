<?php
/**
 * Wrapper específico de caja menor sobre element/progress_stepper.
 *
 * @var \App\View\AppView $this
 * @var string $status
 * @var bool $isRejected (optional)
 */
use App\Constants\PettyCashConstants;
use App\View\Presentation\PettyCashPresentation;

echo $this->element('progress_stepper', [
    'statuses'      => PettyCashConstants::STATUSES,
    'labels'        => PettyCashConstants::STATUS_LABELS,
    'icons'         => PettyCashPresentation::STATUS_ICONS,
    'currentStatus' => $status ?? '',
    'isRejected'    => $isRejected ?? false,
]);

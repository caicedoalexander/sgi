<?php
/**
 * @var \App\View\AppView $this
 * @var array $params
 * @var string $message
 */
if (!isset($params['escape']) || $params['escape'] !== false) {
    $message = h($message);
}
echo $this->element('flash/_toast', [
    'variant' => 'danger',
    'title' => 'Error',
    'icon' => 'bi-exclamation-triangle',
    'message' => $message,
    'autoDismiss' => false,
]);
